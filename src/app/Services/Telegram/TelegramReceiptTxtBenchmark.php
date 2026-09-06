<?php

namespace App\Services\Telegram;

use App\Jobs\SendTelegramReceiptTxt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;
use InvalidArgumentException;

/**
 * Runs the TXT exporter and delivery path against caller-provided synthetic
 * fixtures. It reports measurements only; it never includes the document
 * contents or any fixture credential value in the result.
 */
final class TelegramReceiptTxtBenchmark
{
    public const ITEM_SIZES = [1, 5, 20, 50];

    private const QUERY_HEADROOM = 2;

    private const RSS_REFERENCE_LIMIT_BYTES = 25_165_824;

    private const RENDER_REFERENCE_LIMIT_MS = 250;

    private const TELEGRAM_REFERENCE_LIMIT_MS = 2_000;

    private const QUEUE_REFERENCE_LIMIT_MS = 1_000;

    private const EFFECT_REFERENCE_LIMIT_MS = 5_000;

    public function __construct(
        private readonly TelegramOutboundEffectService $effects,
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly TelegramOutboundResponseValidator $responseValidator,
        private readonly TelegramPaidOrderTxtExporter $exporter,
        private readonly TelegramReceiptObservability $observability,
        private readonly TelegramLocale $locale,
        private readonly TelegramSupportContact $supportContact,
    ) {}

    /**
     * @param  array<int, array{order: Order, customer: Customer}>  $fixtures
     * @return array<string, mixed>
     */
    public function run(array $fixtures): array
    {
        $this->assertFixtures($fixtures);
        $previousTiming = (bool) config('services.dogfood_timing.enabled', false);
        config()->set('services.dogfood_timing.enabled', true);

        try {
            $samples = [];
            foreach (self::ITEM_SIZES as $itemSize) {
                $samples[] = $this->sample($itemSize, $fixtures[$itemSize]);
            }
        } finally {
            config()->set('services.dogfood_timing.enabled', $previousTiming);
        }

        $thresholds = $this->thresholds($samples[0]);
        $samples = array_map(
            fn (array $sample): array => $sample + [
                'pass' => $this->samplePasses($sample, $thresholds),
                'violations' => $this->violations($sample, $thresholds),
            ],
            $samples,
        );

        return [
            'benchmark' => 'telegram_receipt_txt',
            'fixture' => [
                'item_sizes' => self::ITEM_SIZES,
                'credentials' => 'synthetic_values_not_recorded',
                'max_output_bytes' => TelegramDocumentPayload::MAX_BYTES,
                'max_order_item_quantity' => 20,
            ],
            'baseline' => [
                'item_size' => self::ITEM_SIZES[0],
                'query_count' => $samples[0]['db_query_count'],
                'render_duration_ms' => $samples[0]['render_duration_ms'],
                'worker_rss_delta_bytes' => $samples[0]['worker_rss_delta_bytes'],
                'telegram_document_duration_ms' => $samples[0]['telegram_document_duration_ms'],
                'queue_wait_ms' => $samples[0]['queue_wait_ms'],
                'effect_completion_time_ms' => $samples[0]['effect_completion_time_ms'],
            ],
            'thresholds' => $thresholds,
            'samples' => $samples,
            'pass' => collect($samples)->every(static fn (array $sample): bool => $sample['pass'] === true),
        ];
    }

    /**
     * @param  array{order: Order, customer: Customer}  $fixture
     * @return array<string, mixed>
     */
    private function sample(int $itemSize, array $fixture): array
    {
        $order = $fixture['order'];
        $customer = $fixture['customer'];

        DogfoodTiming::resetQueries();
        $renderStartedAt = DogfoodTiming::now();
        $rssBefore = DogfoodTiming::residentMemoryBytes();
        $renderQuerySnapshot = DogfoodTiming::querySnapshot();
        $payload = $this->exporter->export($order->fresh(), $customer);
        $renderDurationMs = DogfoodTiming::elapsedMs($renderStartedAt);
        $rssAfter = DogfoodTiming::residentMemoryBytes();
        $renderQueryStats = DogfoodTiming::queryDelta($renderQuerySnapshot);
        if ($payload === null) {
            throw new InvalidArgumentException('Benchmark fixture did not produce a credential payload.');
        }

        $outputBytes = $payload->byteLength();
        unset($payload);

        $requestNonce = str_pad(dechex($itemSize), 32, '0', STR_PAD_LEFT);
        $effectStartedAt = DogfoodTiming::now();
        $effect = $this->effects->recordReceiptTxtDownload(
            $order,
            $requestNonce,
            $customer,
            ['correlation_id' => DogfoodTiming::correlation($requestNonce)],
        );
        if (! $effect instanceof TelegramOutboundEffect) {
            throw new InvalidArgumentException('Benchmark fixture did not produce a TXT effect.');
        }

        $bot = new FakeTelegramBotClient;
        $pipeline = new ReceiptTxtDeliveryPipeline(
            $this->ledger,
            $bot,
            $this->responseValidator,
            $this->exporter,
            $this->observability,
            $this->locale,
            $this->supportContact,
        );
        $jobEnqueuedAt = now()->subMilliseconds(10)->toISOString();
        $queueWaitMs = DogfoodTiming::queueWaitMs($jobEnqueuedAt) ?? 0;
        (new SendTelegramReceiptTxt(
            (string) $order->getKey(),
            $jobEnqueuedAt,
            (string) $effect->getKey(),
        ))->handle($pipeline);
        $effectCompletionTimeMs = DogfoodTiming::elapsedMs($effectStartedAt);

        $completed = $effect->fresh();
        $documentCall = collect($bot->calls)
            ->first(static fn (array $call): bool => ($call['method'] ?? null) === 'sendDocument');
        $telegramDocumentDurationMs = is_array($documentCall)
            ? (float) ($documentCall['duration_ms'] ?? 0.0)
            : null;

        return [
            'item_size' => $itemSize,
            'db_query_count' => (int) ($renderQueryStats['db_query_count'] ?? 0),
            'db_query_total_ms' => (float) ($renderQueryStats['db_query_total_ms'] ?? 0.0),
            'db_query_slowest_ms' => (float) ($renderQueryStats['db_query_slowest_ms'] ?? 0.0),
            'render_duration_ms' => $renderDurationMs,
            'output_bytes' => $outputBytes,
            'worker_rss_before_bytes' => $rssBefore,
            'worker_rss_after_bytes' => $rssAfter,
            'worker_rss_delta_bytes' => max(0, $rssAfter - $rssBefore),
            'queue_wait_ms' => $queueWaitMs,
            'telegram_document_duration_ms' => $telegramDocumentDurationMs,
            'effect_completion_time_ms' => $effectCompletionTimeMs,
            'effect_state' => $completed?->state?->value,
            'effect_correlation_id' => DogfoodTiming::correlation($effect->getKey()),
        ];
    }

    /** @param array<int, array{order: Order, customer: Customer}> $fixtures */
    private function assertFixtures(array $fixtures): void
    {
        foreach (self::ITEM_SIZES as $itemSize) {
            if (! isset($fixtures[$itemSize])
                || ! ($fixtures[$itemSize]['order'] ?? null) instanceof Order
                || ! ($fixtures[$itemSize]['customer'] ?? null) instanceof Customer) {
                throw new InvalidArgumentException('TXT benchmark fixtures must cover 1, 5, 20, and 50 items.');
            }
        }
    }

    /** @return array<string, int|float> */
    private function thresholds(array $baseline): array
    {
        $baselineQueryCount = max(0, (int) $baseline['db_query_count']);
        $baselineRenderMs = max(0.0, (float) $baseline['render_duration_ms']);
        $baselineRssDelta = max(0, (int) $baseline['worker_rss_delta_bytes']);
        $baselineTelegramMs = max(0.0, (float) ($baseline['telegram_document_duration_ms'] ?? 0.0));
        $baselineQueueMs = max(0, (int) $baseline['queue_wait_ms']);
        $baselineEffectMs = max(0.0, (float) $baseline['effect_completion_time_ms']);

        return [
            'max_query_count' => $baselineQueryCount + self::QUERY_HEADROOM,
            'max_output_bytes' => TelegramDocumentPayload::MAX_BYTES,
            'max_render_duration_ms' => max(self::RENDER_REFERENCE_LIMIT_MS, ceil($baselineRenderMs * 4)),
            'max_worker_rss_delta_bytes' => max(self::RSS_REFERENCE_LIMIT_BYTES, $baselineRssDelta + 8_388_608),
            'max_queue_wait_ms' => max(self::QUEUE_REFERENCE_LIMIT_MS, ($baselineQueueMs * 4) + 50),
            'max_telegram_document_duration_ms' => max(self::TELEGRAM_REFERENCE_LIMIT_MS, ceil(($baselineTelegramMs * 4) + 25)),
            'max_effect_completion_time_ms' => max(self::EFFECT_REFERENCE_LIMIT_MS, ceil(($baselineEffectMs * 4) + 100)),
        ];
    }

    /** @param array<string, int|float|null> $sample @param array<string, int|float> $thresholds */
    private function samplePasses(array $sample, array $thresholds): bool
    {
        return $this->violations($sample, $thresholds) === [];
    }

    /**
     * @param  array<string, int|float|null>  $sample
     * @param  array<string, int|float>  $thresholds
     * @return list<string>
     */
    private function violations(array $sample, array $thresholds): array
    {
        $violations = [];
        $checks = [
            'query_count' => [(float) $sample['db_query_count'], (float) $thresholds['max_query_count']],
            'output_bytes' => [(float) $sample['output_bytes'], (float) $thresholds['max_output_bytes']],
            'render_duration_ms' => [(float) $sample['render_duration_ms'], (float) $thresholds['max_render_duration_ms']],
            'worker_rss_delta_bytes' => [(float) $sample['worker_rss_delta_bytes'], (float) $thresholds['max_worker_rss_delta_bytes']],
            'queue_wait_ms' => [(float) $sample['queue_wait_ms'], (float) $thresholds['max_queue_wait_ms']],
            'telegram_document_duration_ms' => [(float) ($sample['telegram_document_duration_ms'] ?? PHP_FLOAT_MAX), (float) $thresholds['max_telegram_document_duration_ms']],
            'effect_completion_time_ms' => [(float) $sample['effect_completion_time_ms'], (float) $thresholds['max_effect_completion_time_ms']],
        ];

        foreach ($checks as $name => [$actual, $maximum]) {
            if ($actual > $maximum) {
                $violations[] = $name;
            }
        }

        if ($sample['effect_state'] !== 'sent') {
            $violations[] = 'effect_state';
        }

        return $violations;
    }
}
