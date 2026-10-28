<?php

namespace App\Services\Telegram\LatencyBaseline;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\DogfoodTiming;
use App\Support\QueueTopology;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Symfony\Component\Console\Output\NullOutput;
use Throwable;

/**
 * Repeatable local latency baseline for the paid receipt path.
 *
 * The runner drives the real payment observation, paid lifecycle, receipt
 * effect pipeline and admin notification owners against disposable
 * PostgreSQL/Redis. Only the external provider/Telegram transports are local
 * fixtures. Scenarios and delay fixtures are recorded in the report.
 */
final class TelegramLatencyBaseline
{
    public const SCENARIOS = [
        'idle',
        'backlog-expired',
        'slow-notification',
        'slow-payment',
        'slow-provider',
        'slow-telegram',
        'burst-interactions',
        'failed-provider',
        'failed-telegram',
        'ambiguous-telegram',
        'redis-loss',
    ];

    public const WORKER_TOPOLOGIES = ['split', 'combined'];

    private const SLOW_TRANSPORT_MS = 500;

    private const SLOW_PAYMENT_PROBE_MS = 3000;

    private KlikQrisCallbackProcessor $observations;

    private KlikQrisNativeQrisService $nativeQris;

    public function __construct(
        private readonly CartService $carts,
        private readonly OrderCheckoutService $checkout,
        private readonly LatencyBaselineReport $report,
    ) {}

    /**
     * @param  array{scenarios: list<string>, batches: int, samples: int, warmup_batches: int, interval_ms: int, timeout_seconds: int, worker_sleep: int, worker_topology: string, provider_latency_ms: int, telegram_latency_ms: int, backlog: int, output: string, run_id: string}  $options
     * @return array<string, mixed>
     */
    public function run(array $options): array
    {
        $this->assertDisposableEnvironment();
        Http::preventStrayRequests();
        LatencyBaselineTransport::install();
        $this->configureLocalProvider();

        $collector = new LatencyBaselineEventCollector;
        $collector->register();

        $provider = new KlikQrisLatencyFixtureProvider;
        app()->instance(KlikQrisProviderAdapter::class, $provider);
        $this->nativeQris = app(KlikQrisNativeQrisService::class);
        $this->observations = app(KlikQrisCallbackProcessor::class);

        $this->ensurePaymentSetting();
        DB::table('failed_jobs')->delete();

        $scenarioResults = [];
        $ordinal = 0;

        foreach ($options['scenarios'] as $scenario) {
            $scenarioResults[$scenario] = $this->runScenario(
                $scenario,
                $options,
                $provider,
                $collector,
                $ordinal,
            );
        }
        $payments = Payment::query()
            ->get(['id', 'order_id'])
            ->keyBy(static fn (Payment $p): string => DogfoodTiming::correlation($p->order_id));
        $finalAudit = [];
        foreach ($scenarioResults as $scenario => $result) {
            foreach ($result['samples'] as $sample) {
                $payment = $payments->get($sample['order_correlation_id']);
                $audit = $this->buildRawSample($sample + [
                    'order_id' => (string) $payment->order_id,
                    'payment_id' => (string) $payment->id,
                    'expected_ambiguous' => $scenario === 'ambiguous-telegram',
                ], $collector);
                $finalAudit[] = array_intersect_key($audit, array_flip([
                    'order_correlation_id', 'correctness_pass', 'settlement_events', 'stock_allocated',
                    'quantity', 'duplicate_payload_sends', 'wrong_recipient_sends', 'unknown_credential_effects',
                    'credential_transport_attempts', 'expected_blocked', 'receipt_sent', 'failed_jobs',
                ]));
            }
        }

        return [
            'run_id' => $options['run_id'],
            'generated_at' => now()->toISOString(),
            'contract' => $this->contract(),
            'environment' => $this->environment(),
            'scenarios' => $scenarioResults,
            'final_audit' => $finalAudit,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function runScenario(
        string $scenario,
        array $options,
        KlikQrisLatencyFixtureProvider $provider,
        LatencyBaselineEventCollector $collector,
        int &$ordinal,
    ): array {
        $batches = [];
        $samples = [];
        for ($batch = 0; $batch < $options['warmup_batches'] + $options['batches']; $batch++) {
            $result = $this->runBatch($scenario, array_replace($options, [
                'batches' => 1,
                'warmup_batches' => 0,
            ]), $provider, $collector, $ordinal);
            foreach ($result['samples'] as $sample) {
                $samples[] = array_replace($sample, [
                    'batch' => $batch,
                    'cold' => $batch < $options['warmup_batches'],
                ]);
            }
            $batches[] = [
                'batch' => $batch,
                'cold' => $batch < $options['warmup_batches'],
                'dataset' => $result['dataset'],
                'resources' => $result['resources'],
                'interaction_probe' => $result['interaction_probe'],
                'interactions' => $result['interactions'],
                'redis_recovery' => $result['redis_recovery'],
                'history' => $result['history'],
            ];
        }
        $cold = array_values(array_filter($samples, static fn (array $s): bool => $s['cold']));
        $warm = array_values(array_filter($samples, static fn (array $s): bool => ! $s['cold']));

        return array_replace($result, [
            'batches' => $batches,
            'dataset' => array_replace($result['dataset'], [
                'batches' => $options['batches'],
                'warmup_batches' => $options['warmup_batches'],
                'orders_seeded' => count($samples),
                'batch_isolation' => 'fresh workers; wait and stop before next batch',
            ]),
            'samples' => $samples,
            'cold' => $this->report->summarize($cold),
            'warm' => $this->report->summarize($warm),
        ]);
    }

    private function runBatch(
        string $scenario,
        array $options,
        KlikQrisLatencyFixtureProvider $provider,
        LatencyBaselineEventCollector $collector,
        int &$ordinal,
    ): array {
        $config = $this->scenarioConfig($scenario, $options);
        $samples = [];

        for ($batch = 0; $batch < $options['warmup_batches'] + $options['batches']; $batch++) {
            $cold = $batch < $options['warmup_batches'];

            for ($index = 0; $index < $options['samples']; $index++) {
                $ordinal++;
                $samples[] = $this->seedOrder($batch, $index, $cold, $ordinal, $config['burst'] || $config['telegram_fault'] === 'ambiguous' ? 10 : 1);
            }
        }

        $this->clearQueues();

        $provider->pendingMerchantRefs = [];
        $providerBacklog = $this->seedProviderBacklog($config, $ordinal, $provider);
        foreach ($samples as $sample) {
            $provider->pendingMerchantRefs[] = (string) Payment::findOrFail($sample['payment_id'])->merchant_ref;
        }
        $notificationBacklog = $this->seedNotificationBacklog($config, $ordinal);
        $workers = $this->workers($config, $options);

        $provider->statusDelayMs = $config['provider_latency_ms'];
        $provider->statusRequests = 0;
        $provider->failStatus = false;
        $provider->historyRequests = 0;
        $provider->observedHistory = [];
        $deferWorkers = $config['redis_loss'];
        if (! $deferWorkers) {
            foreach ($workers as $worker) {
                $worker->start();
            }
        }

        $interactionProbe = null;
        $interactions = [];
        $redisRecovery = null;

        try {
            if ($config['interaction_probe'] && ! $config['burst']) {
                $interactionProbe = $this->runInteractionProbe($config, $options, $collector, $workers, $ordinal);
            }

            if ($config['burst']) {
                $interactions = app(LatencyBaselineInteractions::class)->enqueue($samples, function () use ($collector, $workers, $samples): void {
                    if ($this->waitForEvent($collector, $workers, 'dogfood.telegram.baseline_provider_started', $samples[0]['payment_correlation_id'], 15) === null) {
                        throw new RuntimeException('The real status refresh did not reach the delayed provider fixture.');
                    }
                });
            }
            foreach ($samples as $sampleIndex => $sample) {
                $reference = (string) Payment::findOrFail($sample['payment_id'])->merchant_ref;
                $provider->pendingMerchantRefs = array_values(array_diff($provider->pendingMerchantRefs, [$reference]));
                if ($config['provider_fail']) {
                    $provider->failStatus = true;
                    try {
                        $this->observe($sample, $provider);
                    } catch (ConnectionException) {
                        $sample['provider_failures'] = 1;
                        $sample['pending_after_outage'] = Payment::findOrFail($sample['payment_id'])->status === PaymentStatus::Pending;
                    } finally {
                        $provider->failStatus = false;
                    }
                }
                $sample['provider_requests_before'] = $provider->statusRequests;
                if ($config['provider_backlog'] > 0) {
                    $this->observeViaReconciliationBacklog($sample, $config, $provider);
                } else {
                    $this->observe($sample, $provider);
                }
                $samples[$sampleIndex] = $sample + [
                    'observation_finished_at' => now()->toISOString(),
                ];
                $samples[$sampleIndex]['provider_requests'] = $provider->statusRequests - $sample['provider_requests_before']
                    + ($sample['provider_failures'] ?? 0);

                if ($options['interval_ms'] > 0) {
                    usleep($options['interval_ms'] * 1000);
                }
            }

            if ($config['redis_loss']) {
                $queuedBefore = $this->queueSize();
                Redis::connection()->command('flushdb');
                Redis::connection('cache')->command('flushdb');
                $queuedAfterLoss = $this->queueSize();
                Artisan::call('telegram:recover-outbound', ['--chunk' => 100, '--max-items' => 100], new NullOutput);
                $redisRecovery = [
                    'queued_before_loss' => $queuedBefore,
                    'queued_after_loss' => $queuedAfterLoss,
                    'queued_after_recovery' => $this->queueSize(),
                    'paid_after_loss' => Payment::whereIn('id', array_column($samples, 'payment_id'))->where('status', 'paid')->count(),
                ];
                foreach ($workers as $worker) {
                    $worker->start();
                }
            }
            $this->waitForBatch(
                $samples,
                $collector,
                array_values($workers),
                $config['telegram_fault'] === 'ambiguous' ? 8 : $options['timeout_seconds'],
                $interactionProbe['update_id'] ?? null,
            );
            if ($config['burst']) {
                $this->waitForEvent($collector, $workers, 'dogfood.telegram.baseline_provider_finished', $samples[0]['payment_correlation_id'], 15, 'worker');
            }
            $interactions = app(LatencyBaselineInteractions::class)->finish(
                $interactions, $collector, fn () => $this->pumpWorkers($collector, $workers), $options['timeout_seconds'],
            );
            if ($config['telegram_fault'] === 'ambiguous') {
                Artisan::call('telegram:recover-outbound', ['--chunk' => 100, '--max-items' => 100], new NullOutput);
                usleep(1_000_000);
                $this->pumpWorkers($collector, $workers);
            }
        } finally {
            foreach ($workers as $worker) {
                $worker->stop();
                $collector->ingestWorkerOutput($worker->output());
            }
        }

        if ($interactionProbe !== null) {
            $interactionProbe = $this->finalizeInteractionProbe(
                $interactionProbe,
                $collector,
                $workers,
            );
        }

        $raw = [];
        foreach ($samples as $sample) {
            $raw[] = $this->buildRawSample($sample + ['expected_ambiguous' => $config['telegram_fault'] === 'ambiguous'], $collector);
        }
        $history = [
            'fixture_requests' => $provider->statusRequests,
            'history_requests' => $provider->historyRequests,
            'distinct_history_observed' => count($provider->observedHistory),
            'worker_verification_requests' => array_sum(array_map(
                static fn (array $s): int => count(array_filter(
                    $collector->matching('dogfood.telegram.baseline_provider_started', $s['payment_correlation_id']),
                    static fn (array $e): bool => $e['source'] === 'worker',
                )), $samples,
            )),
        ];

        $coldSamples = array_values(array_filter($raw, static fn (array $sample): bool => $sample['cold'] === true));
        $warmSamples = array_values(array_filter($raw, static fn (array $sample): bool => $sample['cold'] === false));

        return [
            'dataset' => [
                'batches' => $options['batches'],
                'warmup_batches' => $options['warmup_batches'],
                'samples_per_batch' => $options['samples'],
                'orders_seeded' => count($samples),
                'provider_history_backlog' => $providerBacklog,
                'notification_backlog' => $notificationBacklog,
                'worker_topology' => $options['worker_topology'],
                'interval_ms' => $options['interval_ms'],
                'timeout_seconds' => $options['timeout_seconds'],
                'product_quantity' => $samples[0]['quantity'],
                'stock_source' => 'local Product Stock Item allocated by checkout',
            ],
            'worker' => reset($workers)->description(),
            'workers' => array_map(
                static fn (LatencyBaselineWorker $worker): array => $worker->description(),
                $workers,
            ),
            'resources' => $this->resourceSummary($workers),
            'interaction_probe' => $interactionProbe,
            'interactions' => $interactions,
            'redis_recovery' => $redisRecovery,
            'history' => $history,
            'fixture' => [
                'provider' => KlikQrisLatencyFixtureProvider::class,
                'provider_status_delay_ms' => $config['provider_latency_ms'],
                'provider_paid_offset_seconds' => 2,
                'provider_history_status' => $config['provider_backlog'] > 0 ? 'PENDING' : 'SUCCESS',
                'observation_driver' => $config['provider_backlog'] > 0
                    ? 'klikqris:reconcile with a bounded max-payments budget'
                    : 'direct KlikQRIS reconciliation owner call',
                'telegram_transport' => 'TelegramBotClient with bounded local HTTP fakes and stray HTTP blocked',
                'telegram_latency_ms' => $config['telegram_latency_ms'],
                'telegram_fault' => $config['telegram_fault'],
                'provider_outage' => $config['provider_fail'],
                'admin_target' => 'synthetic numeric chat from TELEGRAM_ADMIN_CHAT_ID',
                'queue_probe_latency_ms' => $config['probe_latency_ms'],
            ],
            'cold' => $this->report->summarize($coldSamples),
            'warm' => $this->report->summarize($warmSamples),
            'samples' => $raw,
        ];
    }

    /**
     * @return array{provider_latency_ms: int, telegram_latency_ms: int, provider_backlog: int, notification_backlog: int}
     */
    private function scenarioConfig(string $scenario, array $options): array
    {
        $base = [
            'provider_latency_ms' => $options['provider_latency_ms'],
            'telegram_latency_ms' => $options['telegram_latency_ms'],
            'provider_backlog' => 0,
            'notification_backlog' => 0,
            'interaction_probe' => false,
            'probe_latency_ms' => 0,
            'burst' => false,
            'provider_fail' => false,
            'telegram_fault' => 'none',
            'redis_loss' => false,
        ];

        return match ($scenario) {
            'idle' => $base,
            'backlog-expired' => array_replace($base, ['provider_backlog' => max(1, $options['backlog'])]),
            'slow-notification' => array_replace($base, [
                'telegram_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['telegram_latency_ms']),
                'notification_backlog' => 1,
            ]),
            'slow-payment' => array_replace($base, [
                'interaction_probe' => true,
                'probe_latency_ms' => self::SLOW_PAYMENT_PROBE_MS,
            ]),
            'slow-provider' => array_replace($base, [
                'provider_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['provider_latency_ms']),
            ]),
            'slow-telegram' => array_replace($base, [
                'telegram_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['telegram_latency_ms']),
            ]),
            'burst-interactions' => array_replace($base, ['burst' => true, 'interaction_probe' => true, 'probe_latency_ms' => self::SLOW_PAYMENT_PROBE_MS]),
            'failed-provider' => array_replace($base, ['provider_fail' => true, 'provider_latency_ms' => max(self::SLOW_TRANSPORT_MS, $options['provider_latency_ms'])]),
            'failed-telegram' => array_replace($base, ['telegram_fault' => 'rejected']),
            'ambiguous-telegram' => array_replace($base, ['telegram_fault' => 'ambiguous']),
            'redis-loss' => array_replace($base, ['redis_loss' => true]),
            default => throw new RuntimeException('Unknown latency baseline scenario: '.$scenario),
        };
    }

    /**
     * @return array<string, LatencyBaselineWorker>
     */
    private function workers(array $config, array $options): array
    {
        $maxTime = max(120, $options['timeout_seconds'] * 2);
        $baseEnvironment = $this->workerEnvironment($config);
        $fastTelegramEnvironment = array_replace($baseEnvironment, [
            'DOGFOOD_FAKE_TELEGRAM_LATENCY_US' => '0',
        ]);
        $topology = [];

        if ($options['worker_topology'] === 'combined') {
            $topology['receipt-and-notifications'] = [
                'queue' => QueueTopology::NOTIFICATIONS.','.TelegramOutboundEffectService::RECEIPT_QUEUE,
                'environment' => $baseEnvironment,
            ];

            if ($config['interaction_probe']) {
                $topology['payments-and-interactions'] = [
                    'queue' => QueueTopology::PAYMENTS.','.QueueTopology::INTERACTIONS,
                    'environment' => $baseEnvironment,
                ];
            }
        } else {
            // The slow-notification scenario injects delay only into the
            // unrelated notification worker. Slowing the receipt worker too
            // would measure Telegram transport latency, not queue contention.
            $receiptEnvironment = $config['notification_backlog'] > 0
                ? $fastTelegramEnvironment
                : $baseEnvironment;

            $topology['receipts'] = [
                'queue' => TelegramOutboundEffectService::RECEIPT_QUEUE,
                'environment' => $receiptEnvironment,
            ];
            $topology['notifications'] = [
                'queue' => QueueTopology::NOTIFICATIONS,
                'environment' => $baseEnvironment,
            ];

            if ($config['interaction_probe']) {
                $topology['interactions'] = [
                    'queue' => QueueTopology::INTERACTIONS,
                    'environment' => $baseEnvironment,
                ];
                $topology['payments'] = [
                    'queue' => QueueTopology::PAYMENTS,
                    'environment' => $baseEnvironment,
                ];
            }
        }
        $topology['outbound'] = [
            'queue' => QueueTopology::OUTBOUND,
            'environment' => $baseEnvironment,
        ];

        $workers = [];
        foreach ($topology as $name => $worker) {
            $workers[$name] = new LatencyBaselineWorker(
                $worker['environment'],
                [
                    'queue' => $worker['queue'],
                    'sleep' => $options['worker_sleep'],
                    'tries' => 3,
                    'timeout' => 60,
                    'max_time' => $maxTime,
                ],
            );
        }

        return $workers;
    }

    /**
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array{worker_count: int, worker_peak_rss_total_bytes: int, worker_peak_rss_max_bytes: int}
     */
    private function resourceSummary(array $workers): array
    {
        $rss = array_values(array_filter(array_map(
            static fn (LatencyBaselineWorker $worker): ?int => $worker->description()['peak_rss_bytes'],
            $workers,
        ), static fn (?int $value): bool => $value !== null));

        return [
            'worker_count' => count($workers),
            'worker_peak_rss_total_bytes' => array_sum($rss),
            'worker_peak_rss_max_bytes' => $rss === [] ? 0 : max($rss),
        ];
    }

    /**
     * Occupy the payments queue with a local probe, then enqueue a real
     * Telegram update behind it. The probe never calls a provider.
     *
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array<string, mixed>
     */
    private function runInteractionProbe(
        array $config,
        array $options,
        LatencyBaselineEventCollector $collector,
        array $workers,
        int $ordinal,
    ): array {
        $marker = 'slow-payment-'.$options['run_id'].'-'.$ordinal;
        $probeCorrelation = DogfoodTiming::correlation($marker);

        SlowPaymentVerificationProbe::dispatch($config['probe_latency_ms'], $marker)
            ->onQueue(QueueTopology::PAYMENTS);

        $probeStarted = $this->waitForEvent(
            $collector,
            $workers,
            'dogfood.telegram.queue_probe_started',
            $probeCorrelation,
            10.0,
        );

        $chatId = 8_800_000 + $ordinal;
        $customer = Customer::query()->create([
            'telegram_id' => $chatId,
            'first_name' => 'Latency Update',
            'locale' => 'en',
        ]);
        $update = TelegramUpdate::query()->create([
            'update_id' => random_int(1_000_000_000, 2_000_000_000),
            'customer_id' => $customer->getKey(),
            'payload' => ['message' => [
                'message_id' => 1,
                'date' => now()->timestamp,
                'text' => '/start',
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => [
                    'id' => $chatId,
                    'is_bot' => false,
                    'first_name' => 'Latency Update',
                    'language_code' => 'en',
                ],
            ]],
            'processed_at' => null,
            'created_at' => now(),
        ]);

        $enqueuedAt = now()->toISOString();
        ProcessTelegramUpdate::dispatch($update->id, $enqueuedAt)
            ->onQueue(QueueTopology::INTERACTIONS);

        $pickup = $this->waitForEvent(
            $collector,
            $workers,
            'dogfood.telegram.job_picked_up',
            DogfoodTiming::correlation($update->id),
            10.0,
        );

        return [
            'update_id' => (int) $update->id,
            'correlation_id' => DogfoodTiming::correlation($update->id),
            'probe_correlation_id' => $probeCorrelation,
            'probe_latency_ms' => (int) $config['probe_latency_ms'],
            'probe_started' => $probeStarted !== null,
            'interaction_queue' => QueueTopology::INTERACTIONS,
            'payment_queue' => QueueTopology::PAYMENTS,
            'worker_topology' => $options['worker_topology'],
            'enqueued_at' => $enqueuedAt,
            'queue_wait_ms' => is_numeric($pickup['context']['queue_wait_ms'] ?? null)
                ? (int) $pickup['context']['queue_wait_ms']
                : null,
            'picked_up_at' => $pickup['context']['job_picked_up_at'] ?? null,
            'picked_up' => $pickup !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $probe
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array<string, mixed>
     */
    private function finalizeInteractionProbe(
        array $probe,
        LatencyBaselineEventCollector $collector,
        array $workers,
    ): array {
        $this->pumpWorkers($collector, $workers);
        $update = TelegramUpdate::query()->find($probe['update_id']);

        $finished = $collector->first(
            'dogfood.telegram.queue_probe_finished',
            (string) $probe['probe_correlation_id'],
        );
        $pickupToProcessed = $probe['picked_up_at'] !== null && $update?->processed_at !== null
            ? $this->diffMs((string) $probe['picked_up_at'], $update->processed_at->toISOString())
            : null;

        return [
            ...array_diff_key($probe, ['update_id' => true]),
            'processed' => $update?->processed_at !== null,
            'terminal_failed' => $update?->terminal_failed_at !== null,
            'pickup_to_processed_ms' => $pickupToProcessed,
            'probe_finished' => $finished !== null,
            'complete' => ($probe['probe_started'] ?? false) === true
                && $probe['picked_up'] === true
                && $finished !== null
                && $update?->processed_at !== null
                && $update?->terminal_failed_at === null,
        ];
    }

    /**
     * @param  array<string, LatencyBaselineWorker>  $workers
     * @return array<string, mixed>|null
     */
    private function waitForEvent(
        LatencyBaselineEventCollector $collector,
        array $workers,
        string $event,
        string $correlation,
        float $timeoutSeconds,
        ?string $source = null,
    ): ?array {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $this->pumpWorkers($collector, $workers);

            $match = array_find($collector->matching($event, $correlation), static fn (array $e): bool => $source === null || $e['source'] === $source);
            if ($match !== null) {
                return $match;
            }

            usleep(50_000);
        }

        return array_find($collector->matching($event, $correlation), static fn (array $e): bool => $source === null || $e['source'] === $source);
    }

    /**
     * @param  array<string, LatencyBaselineWorker>  $workers
     */
    private function pumpWorkers(LatencyBaselineEventCollector $collector, array $workers): void
    {
        foreach ($workers as $worker) {
            $worker->pump();
            $collector->ingestWorkerOutput($worker->output());
        }
    }

    /**
     * @return array{order_id: string, payment_id: string, order_correlation_id: string, payment_correlation_id: string, cold: bool, batch: int, index: int, observation_started_at: string}
     */
    private function seedOrder(int $batch, int $index, bool $cold, int $ordinal, int $quantity = 1): array
    {
        $customer = Customer::query()->create([
            'telegram_id' => 8_500_000 + $ordinal,
            'first_name' => 'Latency Baseline',
            'locale' => 'en',
        ]);

        $product = Product::query()->create([
            'name' => 'Baseline Product '.$ordinal,
            'description' => 'Synthetic latency baseline fixture.',
            'price_amount' => 10_000,
            'currency' => 'IDR',
            'is_active' => true,
        ]);
        $product->forceFill(['stock' => $quantity + 1, 'reserved_stock' => 0])->save();

        foreach (range(1, $quantity + 1) as $credential) {
            ProductStockItem::query()->create([
                'product_id' => $product->getKey(),
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => [
                    'version' => 1,
                    'email' => 'latency-'.$ordinal.'-'.$credential.'@example.test',
                    'password' => 'synthetic-baseline-password-'.$ordinal.'-'.$credential,
                    'access_method' => CredentialAccessMethod::None->value,
                    'access_value' => null,
                    'tutorial_override' => null,
                ],
            ]);
        }

        $cart = $this->carts->activeCartFor($customer);
        $this->carts->addItem($cart, $product, $quantity);
        $order = $this->checkout->checkout($cart, CheckoutPaymentIntents::klikQris());
        $payment = $order->payments()->sole();

        $lifecycle = $this->nativeQris->lifecycle($payment);
        if (! $lifecycle->isPayable()) {
            $payment->refresh();
            throw new RuntimeException(sprintf(
                'Latency baseline fixture order did not produce a payable provider quote (outcome=%s, state=%s, error=%s).',
                $lifecycle->outcome()->value,
                (string) $payment->provider_create_state,
                (string) $payment->provider_create_last_error,
            ));
        }

        $payment->refresh();

        return [
            'order_id' => (string) $order->getKey(),
            'payment_id' => (string) $payment->getKey(),
            'quantity' => $quantity,
            'order_correlation_id' => DogfoodTiming::correlation($order->getKey()),
            'payment_correlation_id' => DogfoodTiming::correlation($payment->getKey()),
            'cold' => $cold,
            'batch' => $batch,
            'index' => $index,
            'observation_started_at' => '',
        ];
    }

    /**
     * @param  array{provider_latency_ms: int, telegram_latency_ms: int, provider_backlog: int, notification_backlog: int}  $config
     */
    private function seedProviderBacklog(array $config, int $ordinal, KlikQrisLatencyFixtureProvider $provider): int
    {
        if ($config['provider_backlog'] < 1) {
            return 0;
        }
        $existing = Payment::where('merchant_ref', 'like', 'LATENCY-HISTORY-PAY-%')->pluck('merchant_ref')->all();
        if ($existing !== []) {
            $provider->pendingMerchantRefs = $existing;

            return count($existing);
        }

        for ($index = 1; $index <= $config['provider_backlog']; $index++) {
            $customer = Customer::query()->create([
                'telegram_id' => 8_700_000 + $ordinal + $index,
                'first_name' => 'Latency Provider History',
                'locale' => 'en',
            ]);
            $createdAt = now()->subMinutes(30)->addSeconds($index);
            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'invoice_number' => 'LATENCY-HISTORY-'.$ordinal.'-'.$index,
                'order_status' => OrderStatus::Expired,
                'payment_status' => PaymentStatus::Expired,
                'total_amount' => 10_000,
                'currency' => 'IDR',
                'expires_at' => now()->subMinutes(10),
                'created_at' => $createdAt,
            ]);
            $merchantRef = 'LATENCY-HISTORY-PAY-'.$ordinal.'-'.$index;
            $payment = Payment::query()->create([
                'order_id' => $order->getKey(),
                'provider' => 'klikqris',
                'merchant_ref' => $merchantRef,
                'method' => 'QRIS',
                'amount' => 10_000,
                'currency' => 'IDR',
                'status' => PaymentStatus::Expired,
                'provider_total_payment' => 10_016,
                'provider_fee' => 16,
                'provider_payment_method' => 'qris',
                'klikqris_transaction_signature' => 'latency-history-signature-'.$ordinal.'-'.$index,
                'provider_qris_url' => 'https://klikqris.com/storage/sandbox/qris_latency_history.png',
                'provider_payload' => ['data' => [
                    'order_id' => $merchantRef,
                    'amount' => 10_000,
                    'total_amount' => 10_016,
                    'status' => 'PENDING',
                    'qris_url' => 'https://klikqris.com/storage/sandbox/qris_latency_history.png',
                    'expired_at' => now()->subMinutes(10)->toIso8601ZuluString(),
                ]],
                'provider_created_at' => $createdAt,
                'provider_expires_at' => now()->subMinutes(10),
                'expired_at' => now()->subMinutes(10),
                'created_at' => $createdAt,
            ]);
            DB::table('orders')->where('id', $order->getKey())->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            DB::table('payments')->where('id', $payment->getKey())->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
            $provider->pendingMerchantRefs[] = $merchantRef;
        }

        return $config['provider_backlog'];
    }

    /**
     * @param  array{provider_latency_ms: int, telegram_latency_ms: int, provider_backlog: int, notification_backlog: int}  $config
     */
    private function seedNotificationBacklog(array $config, int $ordinal): int
    {
        if ($config['notification_backlog'] < 1) {
            return 0;
        }

        for ($index = 1; $index <= $config['notification_backlog']; $index++) {
            $customer = Customer::query()->create([
                'telegram_id' => 8_600_000 + $ordinal + $index,
                'first_name' => 'Latency Backlog',
                'locale' => 'en',
            ]);
            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'invoice_number' => 'LATENCY-BACKLOG-'.$ordinal.'-'.$index,
                'order_status' => OrderStatus::Expired,
                'payment_status' => PaymentStatus::Expired,
                'total_amount' => 10_000,
                'currency' => 'IDR',
                'expires_at' => now()->subHour(),
            ]);
            Payment::query()->create([
                'order_id' => $order->getKey(),
                'provider' => 'manual',
                'merchant_ref' => 'LATENCY-BACKLOG-PAY-'.$ordinal.'-'.$index,
                'method' => 'QR_MANUAL',
                'amount' => 10_000,
                'currency' => 'IDR',
                'status' => PaymentStatus::Expired,
                'expired_at' => now()->subHour(),
            ]);

            SendAdminOrderExpiredNotification::dispatch((string) $order->getKey(), now()->toISOString())
                ->onQueue(QueueTopology::NOTIFICATIONS);
        }

        return $config['notification_backlog'];
    }

    /** @param array<string, mixed> $sample */
    private function observe(array &$sample, KlikQrisLatencyFixtureProvider $provider): void
    {
        $payment = Payment::query()->findOrFail($sample['payment_id']);
        $provider->providerPaidAt = CarbonImmutable::now()->subSeconds(2);
        $sample['observation_started_at'] = ($sample['observation_started_at'] ?? '') ?: now()->toISOString();

        $transaction = $provider->transactionStatus($payment);
        $this->observations->processReconciliationForPayment($payment, $transaction);
        $this->observations->processReconciliationForPayment($payment, $transaction);
    }

    /**
     * Drive the same ten-request sweep on each revision. Cursors survive
     * between samples within a batch so history progress is measurable.
     *
     * @param  array{provider_backlog: int}  $config
     */
    private function observeViaReconciliationBacklog(
        array &$sample,
        array $config,
        KlikQrisLatencyFixtureProvider $provider,
    ): void {
        $sample['observation_started_at'] = now()->toISOString();
        $provider->providerPaidAt = CarbonImmutable::now()->subSeconds(2);
        $sweeps = 0;

        for ($sweep = 1; $sweep <= 4; $sweep++) {
            Artisan::call('klikqris:reconcile', [
                '--chunk' => 100,
                '--max-payments' => 10,
            ], new NullOutput);
            $sweeps = $sweep;

            if (Payment::query()->find($sample['payment_id'])?->status === PaymentStatus::Paid) {
                break;
            }
        }

        $sample['observation_sweeps'] = $sweeps;
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @param  list<LatencyBaselineWorker>  $workers
     */
    private function waitForBatch(
        array $samples,
        LatencyBaselineEventCollector $collector,
        array $workers,
        int $timeoutSeconds,
        ?int $updateId = null,
    ): void {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $this->pumpWorkers($collector, $workers);

            $complete = true;
            foreach ($samples as $sample) {
                $correlation = $sample['order_correlation_id'];
                if ($collector->first('dogfood.telegram.admin_paid_notification_acknowledged', $correlation) === null) {
                    $complete = false;
                    break;
                }

                if ($collector->first('dogfood.telegram.receipt_completed', $correlation) === null
                    && ! TelegramOutboundEffect::query()->where('source_type', Order::class)
                        ->where('source_id', $sample['order_id'])
                        ->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value)
                        ->where('state', TelegramOutboundEffectState::Unknown->value)->exists()) {
                    $complete = false;
                    break;
                }
            }

            if ($complete && $updateId !== null) {
                $update = TelegramUpdate::query()->find($updateId);
                $complete = $update !== null
                    && ($update->processed_at !== null || $update->terminal_failed_at !== null);
            }

            if ($complete) {
                return;
            }

            if (! $this->anyWorkerRunning($workers)) {
                return;
            }

            usleep(100_000);
        }
    }

    /** @param  list<LatencyBaselineWorker>  $workers */
    private function anyWorkerRunning(array $workers): bool
    {
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $sample
     * @return array<string, mixed>
     */
    private function buildRawSample(array $sample, LatencyBaselineEventCollector $collector): array
    {
        $correlation = $sample['order_correlation_id'];
        $observed = array_find($collector->matching('dogfood.telegram.payment_observed', $correlation),
            static fn (array $event): bool => ($event['context']['provider_status'] ?? null) === 'SUCCESS');
        $committed = $collector->first('dogfood.telegram.paid_committed', $correlation);
        $pickups = $collector->matching('dogfood.telegram.receipt_job_picked_up', $correlation);
        $pickup = $pickups[0] ?? null;
        $firstAcks = $collector->matching('dogfood.telegram.receipt_first_credential_acknowledged', $correlation);
        $firstAck = $firstAcks[0] ?? null;
        $completed = $collector->first('dogfood.telegram.receipt_completed', $correlation);
        $admin = $collector->first('dogfood.telegram.admin_paid_notification_acknowledged', $correlation);

        $observedAt = $this->timestamp($observed['context']['application_observed_at'] ?? null);
        $providerPaidAt = $this->timestamp($observed['context']['provider_paid_at'] ?? null);
        $paidCommittedAt = $this->timestamp($committed['context']['paid_committed_at'] ?? null);
        $enqueuedAt = $this->timestamp($pickup['context']['job_enqueued_at'] ?? null);
        $pickedUpAt = $this->timestamp($pickup['context']['job_picked_up_at'] ?? null);
        $firstAckAt = $this->timestamp($firstAck['context']['telegram_acknowledged_at'] ?? null);
        $completedAt = $this->timestamp($completed['context']['receipt_completed_at'] ?? null);
        $adminAckAt = $this->timestamp($admin['context']['telegram_acknowledged_at'] ?? null);

        $queueWait = $pickup['context']['queue_wait_ms'] ?? null;
        $queueWait = is_int($queueWait) || is_float($queueWait)
            ? (int) $queueWait
            : $this->diffMs($enqueuedAt, $pickedUpAt);

        $order = Order::query()->withCount('orderItems')->find($sample['order_id']);
        $payment = Payment::query()->find($sample['payment_id']);
        $effects = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', $sample['order_id'])
            ->get();

        $effectStates = [];
        foreach ($effects->groupBy(fn (TelegramOutboundEffect $effect): string => $effect->effect_type.'@'.$effect->state->value) as $key => $group) {
            $effectStates[$key] = $group->count();
        }

        $failedJobs = DB::table('failed_jobs')
            ->where('payload', 'like', '%'.$sample['order_id'].'%')
            ->count();
        $transport = $collector->transportFor(DogfoodTiming::correlation($order?->customer?->telegram_id));
        $credentialCalls = array_values(array_filter($transport, static fn (array $e): bool => $e['context']['unit_bearing'] === true && $e['context']['receipt_lane'] === true));
        $accepted = array_filter($credentialCalls, static fn (array $e): bool => $e['context']['accepted']);
        $digests = array_map(static fn (array $e): string => $e['context']['content_digest'], $accepted);
        $wrongRecipient = $collector->wrongRecipientCount();
        $stockAllocated = ProductStockItem::whereIn('order_item_id', $order->orderItems()->pluck('id'))->count();
        $receiptEffects = $effects->where('effect_type', TelegramOutboundEffectType::ReceiptCredentials->value);
        $unknown = $receiptEffects->where('state', TelegramOutboundEffectState::Unknown)->count();
        $settlements = $payment->paymentEvents()->where('event_type', 'reconciliation')->whereNotNull('processed_at')->count();

        $raw = [
            'cold' => $sample['cold'],
            'batch' => $sample['batch'],
            'index' => $sample['index'],
            'order_correlation_id' => $correlation,
            'payment_correlation_id' => $sample['payment_correlation_id'],
            'timestamps' => [
                'provider_paid_at' => $providerPaidAt,
                'application_observed_at' => $observedAt,
                'paid_committed_at' => $paidCommittedAt,
                'receipt_enqueued_at' => $enqueuedAt,
                'receipt_picked_up_at' => $pickedUpAt,
                'first_credential_ack_at' => $firstAckAt,
                'receipt_completed_at' => $completedAt,
                'admin_ack_at' => $adminAckAt,
            ],
            'receipt_units' => $receiptEffects->map(static fn ($e): array => [
                'sequence' => $e->sequence,
                'state' => $e->state->value,
                'attempts' => $e->attempts,
                'contains_credentials' => $e->outcome_metadata['contains_credentials'] ?? null,
            ])->values()->all(),
            'provider_requests' => $sample['provider_requests'] ?? 0,
            'provider_failures' => $sample['provider_failures'] ?? 0,
            'pending_after_outage' => $sample['pending_after_outage'] ?? null,
            'stock_allocated' => $stockAllocated,
            'quantity' => $sample['quantity'],
            'settlement_events' => $settlements,
            'credential_transport_attempts' => count($credentialCalls),
            'credential_transport_rejections' => count(array_filter($credentialCalls, static fn (array $e): bool => ! $e['context']['accepted'])),
            'credential_transport_accepted' => count($accepted),
            'duplicate_payload_sends' => count($digests) - count(array_unique($digests)),
            'wrong_recipient_sends' => $wrongRecipient,
            'unknown_credential_effects' => $unknown,
            'receipt_effect_attempts' => (int) $receiptEffects->sum('attempts'),
            'receipt_retry_attempts' => (int) $receiptEffects->sum(static fn ($e): int => max(0, (int) $e->attempts - 1)),
            'provider_observation_ms' => $this->diffMs($this->timestamp($sample['observation_started_at'] ?? null), $observedAt),
            'provider_paid_to_observation_ms' => $this->diffMs($providerPaidAt, $observedAt),
            'observation_to_paid_commit_ms' => $this->diffMs($observedAt, $paidCommittedAt),
            'paid_commit_to_enqueue_ms' => $this->diffMs($paidCommittedAt, $enqueuedAt),
            'queue_wait_ms' => $queueWait,
            'pickup_to_first_credential_ack_ms' => $this->diffMs($pickedUpAt, $firstAckAt),
            'pickup_to_receipt_completion_ms' => $this->diffMs($pickedUpAt, $completedAt),
            'receipt_completion_to_admin_notice_ms' => $this->diffMs($completedAt, $adminAckAt),
            'e2e_observation_to_admin_notice_ms' => $this->diffMs($observedAt, $adminAckAt),
            'observation_sweeps' => $sample['observation_sweeps'] ?? 1,
            'enqueue_stage' => $pickup['context']['enqueue_stage'] ?? 'unknown',
            'enqueue_reason' => $pickup['context']['enqueue_reason'] ?? 'unknown',
            'queue_wait_unknown' => $queueWait === null,
            'receipt_pickups' => count($pickups),
            'duplicate_acknowledgements' => max(0, count($firstAcks) - 1),
            'failed_jobs' => $failedJobs,
            'payment_status' => $payment?->status?->value ?? 'unknown',
            'order_status' => $order?->order_status?->value ?? 'unknown',
            'receipt_sent' => $order?->telegram_receipt_sent_at !== null,
            'admin_notice_sent' => $order?->admin_paid_order_notified_at !== null,
            'order_item_count' => (int) ($order?->order_items_count ?? 0),
            'effect_states' => $effectStates,
        ];

        $raw['missing_boundaries'] = array_keys(array_filter(
            $raw,
            static fn (mixed $value, string $key): bool => array_key_exists($key, LatencyBaselineReport::BOUNDARIES) && $value === null,
            ARRAY_FILTER_USE_BOTH,
        ));
        $raw['complete'] = $raw['missing_boundaries'] === [];
        $raw['expected_blocked'] = ($sample['expected_ambiguous'] ?? false)
            && $unknown === 1
            && $raw['credential_transport_attempts'] === 1
            && ! $raw['receipt_sent'];
        $raw['correctness_pass'] = $raw['payment_status'] === 'paid'
            && $stockAllocated === $sample['quantity']
            && $settlements === 1
            && $raw['duplicate_acknowledgements'] === 0
            && $raw['duplicate_payload_sends'] === 0
            && $wrongRecipient === 0
            && $raw['credential_transport_attempts'] > 0
            && $raw['failed_jobs'] === 0
            && ($raw['pending_after_outage'] ?? true)
            && (($sample['expected_ambiguous'] ?? false) ? $raw['expected_blocked'] : $raw['receipt_sent']);

        return $raw;
    }

    private function timestamp(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function diffMs(?string $from, ?string $to): ?float
    {
        if ($from === null || $to === null) {
            return null;
        }

        try {
            return round(CarbonImmutable::parse($from)->diffInMilliseconds(CarbonImmutable::parse($to), true), 2);
        } catch (Throwable) {
            return null;
        }
    }

    private function clearQueues(): void
    {
        foreach (QueueTopology::operational() as $queue) {
            Artisan::call('queue:clear', [
                'connection' => 'redis',
                '--queue' => $queue,
                '--force' => true,
            ]);
        }

        Cache::flush();
    }

    /** @return array<string, string> */
    private function workerEnvironment(array $config): array
    {
        return [
            'APP_ENV' => (string) config('app.env'),
            'APP_KEY' => (string) config('app.key'),
            'APP_ALLOW_DOGFOOD_RUNTIME' => 'true',
            'QUEUE_CONNECTION' => 'redis',
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'array',
            'DOGFOOD_TIMING_ENABLED' => 'true',
            'DOGFOOD_TIMING_LOG_CHANNEL' => 'stderr',
            'DOGFOOD_FAKE_TELEGRAM_OUTBOUND' => 'true',
            'DOGFOOD_FAKE_TELEGRAM_LATENCY_US' => (string) ($config['telegram_latency_ms'] * 1000),
            'LATENCY_BASELINE_TELEGRAM_FAULT' => $config['telegram_fault'],
            'LATENCY_BASELINE_PROVIDER_DELAY_MS' => $config['burst'] ? '3000' : '0',
            'LATENCY_BASELINE_ADMIN_DELAY_ONLY' => $config['notification_backlog'] > 0 ? 'true' : 'false',
            'TELEGRAM_BOT_TOKEN' => (string) config('services.telegram.token'),
            'TELEGRAM_ADMIN_CHAT_ID' => (string) config('services.telegram.admin_telegram_chat_id'),
            'LOG_LEVEL' => 'debug',
        ];
    }

    private function configureLocalProvider(): void
    {
        config()->set('services.klikqris.sandbox_enabled', true);
        config()->set('services.klikqris.sandbox_base_url', 'https://klikqris.com/api/sandbox');
        config()->set('services.klikqris.sandbox_api_key', 'latency-baseline-fixture-key');
        config()->set('services.klikqris.sandbox_merchant_id', 'latency-baseline-fixture-merchant');
        config()->set('services.klikqris.qris_ttl_minutes', 15);
        config()->set('services.klikqris.status_max_attempts', 1);
        config()->set('services.klikqris.status_retry_delay_ms', 0);
        config()->set('services.klikqris.reconcile_terminal_lookback_hours', 72);
        config()->set('services.klikqris.reconcile_max_execution_seconds', 45);
        config()->set('services.klikqris.http_timeout_seconds', 1.0);
        config()->set('services.klikqris.http_connect_timeout_seconds', 1.0);
        config()->set('services.dogfood_timing.enabled', true);
        config()->set('services.dogfood_timing.log_channel', null);
    }

    private function ensurePaymentSetting(): void
    {
        if (ManualPaymentSetting::query()->where('is_active', true)->doesntExist()) {
            ManualPaymentSetting::query()->create([
                'payment_mode' => 'klikqris',
                'is_active' => true,
            ]);
        }
    }

    private function assertDisposableEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('The latency baseline may only run with APP_ENV=local or testing.');
        }

        if (config('database.default') !== 'pgsql') {
            throw new RuntimeException('The latency baseline requires a disposable PostgreSQL connection.');
        }

        if (config('queue.default') !== 'redis') {
            throw new RuntimeException('The latency baseline requires the Redis queue connection.');
        }

        if (config('cache.default') !== 'redis') {
            throw new RuntimeException('The latency baseline requires the Redis cache store for cross-process worker locks.');
        }

        $database = (string) config('database.connections.pgsql.database');
        if (preg_match('/(baseline|test)/i', $database) !== 1) {
            throw new RuntimeException('Refusing to run: DB_DATABASE must name a disposable baseline/test database.');
        }

        if (! (bool) config('services.dogfood_timing.enabled')) {
            throw new RuntimeException('The latency baseline requires DOGFOOD_TIMING_ENABLED=true.');
        }

        if (! (bool) config('services.dogfood_timing.fake_telegram_outbound')) {
            throw new RuntimeException('The latency baseline requires the local fake Telegram transport; real sends are not allowed.');
        }

        if (blank(config('services.telegram.token'))) {
            throw new RuntimeException('The latency baseline needs a synthetic Telegram token for the admin-notice fixture.');
        }

        if (preg_match('/^[1-9][0-9]*$/', (string) config('services.telegram.admin_telegram_chat_id')) !== 1) {
            throw new RuntimeException('The latency baseline needs a synthetic numeric TELEGRAM_ADMIN_CHAT_ID.');
        }
    }

    /** @return array<string, mixed> */
    private function contract(): array
    {
        return [
            'note' => 'Local repeatable baseline; Telegram acknowledgement is not a read receipt and these percentiles are not a production SLO.',
            'boundaries' => LatencyBaselineReport::BOUNDARIES,
            'percentile' => 'nearest rank over available samples; p95 requires at least 20 known samples; unknown timestamps stay unknown; no p99',
            'cold_start' => 'warm-up batches are reported separately and excluded from warmed percentiles',
        ];
    }

    /** @return array<string, mixed> */
    private function environment(): array
    {
        $database = 'pgsql';
        try {
            $version = DB::selectOne('select version() as version');
            $database = 'pgsql '.($version->version ?? '');
        } catch (Throwable) {
            $database = 'pgsql (version unavailable)';
        }

        $redis = 'redis (version unavailable)';
        try {
            $info = Redis::connection()->command('info');
            $redisVersion = is_array($info)
                ? ($info['redis_version'] ?? null)
                : (preg_match('/^redis_version:(.+)$/mi', (string) $info, $match) === 1 ? trim($match[1]) : null);
            $redis = $redisVersion === null ? 'redis' : 'redis '.$redisVersion;
        } catch (Throwable) {
            $redis = 'redis';
        }

        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'app_env' => (string) config('app.env'),
            'timezone' => (string) config('app.timezone'),
            'database' => $database,
            'redis' => $redis,
            'queue_connection' => (string) config('queue.default'),
            'cache_store' => (string) config('cache.default'),
            'cpu_count' => $this->cpuCount(),
            'memory_limit' => (string) ini_get('memory_limit'),
            'os' => PHP_OS_FAMILY,
            'kernel' => php_uname('r'),
            'architecture' => php_uname('m'),
            'cpu_model' => preg_match('/^model name\\s*:\\s*(.+)$/m', (string) @file_get_contents('/proc/cpuinfo'), $cpu) === 1 ? $cpu[1] : null,
            'machine_memory_kib' => preg_match('/^MemTotal:\\s*(\\d+)/m', (string) @file_get_contents('/proc/meminfo'), $memory) === 1 ? (int) $memory[1] : null,
            'baseline_image' => (string) (getenv('LATENCY_BASELINE_IMAGE') ?: 'unknown'),
            'source_revision' => (string) (getenv('LATENCY_BASELINE_SOURCE_REVISION') ?: 'unknown'),
        ];
    }

    private function queueSize(): int
    {
        return array_sum(array_map(
            static fn (string $queue): int => app('queue')->connection('redis')->size($queue),
            QueueTopology::operational(),
        ));
    }

    private function cpuCount(): ?int
    {
        $cpuInfo = @file_get_contents('/proc/cpuinfo');
        if (is_string($cpuInfo) && preg_match_all('/^processor\s*:/mi', $cpuInfo, $matches) > 0) {
            return count($matches[0]);
        }

        return null;
    }
}
