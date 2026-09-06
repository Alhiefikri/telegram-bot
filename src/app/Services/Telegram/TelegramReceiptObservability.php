<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Order;
use App\Models\TelegramOutboundEffect;
use App\Support\DogfoodTiming;

/**
 * Emits the bounded operational view of the paid-receipt action families.
 *
 * This class is deliberately an allowlist boundary. It accepts domain objects
 * so callers do not have to assemble log context, but it only emits opaque
 * correlations, bounded categories, and measurements. It must never receive
 * or inspect a rendered document, CredentialDelivery, or raw Telegram body.
 */
final class TelegramReceiptObservability
{
    private const COUNTER_EVENT = 'dogfood.telegram.receipt_counter';

    private const RENDER_EVENT = 'dogfood.telegram.receipt_txt_render_observed';

    private const DOCUMENT_EVENT = 'dogfood.telegram.receipt_txt_document';

    /** @var list<string> */
    private const COUNTERS = [
        'receipt_actions_materialized',
        'receipt_actions_sent',
        'receipt_actions_failed',
        'receipt_actions_unknown',
        'receipt_txt_request_accepted',
        'receipt_txt_request_rejected',
        'receipt_txt_request_stale',
        'receipt_txt_sent',
        'receipt_txt_failed',
        'receipt_txt_unknown',
        'receipt_txt_manual_review',
    ];

    /** @var list<string> */
    private const REJECTION_KINDS = [
        'authorization',
        'rate_limit',
        'effect',
    ];

    /** @var list<string> */
    private const REJECTION_CATEGORIES = [
        'chat_mismatch',
        'order_unavailable',
        'payment_not_paid',
        'lifecycle_ineligible',
        'late_payment_review',
        'payment_not_confirmed',
        'credential_projection_unavailable',
        'effect_not_created',
        'download_window',
        'confirmation_unavailable',
    ];

    /** @var list<string> */
    private const OUTCOMES = [
        'ok',
        'failed',
        'ambiguous',
    ];

    /** @var list<string> */
    private const EFFECT_REASONS = [
        'receipt_txt_not_eligible',
        'receipt_txt_credentials_missing',
        'receipt_txt_render_failed',
        'telegram_transport_ambiguous',
        'telegram_http_400',
        'telegram_http_403',
        'telegram_response_missing_message_id',
        'telegram_delivery_failed',
        'receipt_actions_not_eligible',
        'receipt_actions_render_failed',
        'lease_expired',
        'worker_lease_expired',
    ];

    public function actionPanelMaterialized(TelegramOutboundEffect $effect): void
    {
        $metadata = $this->metadata($effect);
        $effectMetadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
        $metadata['product_count_bucket'] = $this->countBucket($effectMetadata['product_count'] ?? null);
        $metadata['item_count_bucket'] = $this->countBucket($effectMetadata['item_count'] ?? null);
        $metadata['txt_available'] = ($effectMetadata['txt_available'] ?? false) === true;

        $this->counter('receipt_actions_materialized', $metadata);
    }

    public function actionPanelOutcome(
        TelegramOutboundEffect $effect,
        string $outcome,
        ?float $completionDurationMs = null,
        ?string $reason = null,
        ?int $queueWaitMs = null,
    ): void {
        $metric = match ($outcome) {
            'sent' => 'receipt_actions_sent',
            'failed' => 'receipt_actions_failed',
            'unknown' => 'receipt_actions_unknown',
            default => null,
        };
        if ($metric === null) {
            return;
        }

        $context = $this->metadata($effect);
        if ($completionDurationMs !== null) {
            $context['effect_completion_duration_ms'] = $this->milliseconds($completionDurationMs);
            $context['effect_completion_duration_bucket'] = $this->durationBucket($completionDurationMs);
        }
        if ($reason !== null) {
            $context['reason'] = $this->reason($reason);
        }
        if ($queueWaitMs !== null) {
            $context['queue_wait_bucket'] = $this->durationBucket($queueWaitMs);
        }

        $this->counter($metric, $context);
    }

    public function txtRequestAccepted(
        ?TelegramOutboundEffect $effect,
        ?Customer $customer = null,
        ?Order $order = null,
        ?string $callbackQueryId = null,
        string $stage = 'effect',
    ): void {
        $stage = in_array($stage, ['confirmation', 'effect'], true) ? $stage : 'effect';
        $this->counter('receipt_txt_request_accepted', $this->metadata(
            $effect,
            $customer,
            $order,
            $callbackQueryId,
        ) + ['request_stage' => $stage]);
    }

    public function txtRequestRejected(
        string $category,
        ?Customer $customer = null,
        ?Order $order = null,
        ?string $callbackQueryId = null,
        string $kind = 'authorization',
    ): void {
        $kind = in_array($kind, self::REJECTION_KINDS, true) ? $kind : 'authorization';
        $category = in_array($category, self::REJECTION_CATEGORIES, true) ? $category : 'order_unavailable';

        $this->counter('receipt_txt_request_rejected', $this->metadata(
            effect: null,
            customer: $customer,
            order: $order,
            callbackQueryId: $callbackQueryId,
        ) + [
            'rejection_kind' => $kind,
            'rejection_category' => $category,
        ]);
    }

    public function txtRequestStale(
        ?Customer $customer = null,
        ?string $callbackQueryId = null,
        string $category = 'confirmation_unavailable',
    ): void {
        $category = in_array($category, self::REJECTION_CATEGORIES, true)
            ? $category
            : 'confirmation_unavailable';

        $this->counter('receipt_txt_request_stale', $this->metadata(
            effect: null,
            customer: $customer,
            order: null,
            callbackQueryId: $callbackQueryId,
        ) + ['rejection_category' => $category]);
    }

    public function txtOutcome(
        TelegramOutboundEffect $effect,
        string $outcome,
        ?float $completionDurationMs = null,
        ?string $reason = null,
    ): void {
        $metric = match ($outcome) {
            'sent' => 'receipt_txt_sent',
            'failed' => 'receipt_txt_failed',
            'unknown' => 'receipt_txt_unknown',
            'manual_review' => 'receipt_txt_manual_review',
            default => null,
        };
        if ($metric === null) {
            return;
        }

        $context = $this->metadata($effect);
        if ($completionDurationMs !== null) {
            $context['effect_completion_duration_ms'] = $this->milliseconds($completionDurationMs);
            $context['effect_completion_duration_bucket'] = $this->durationBucket($completionDurationMs);
        }
        if ($reason !== null) {
            $context['reason'] = $this->reason($reason);
        }

        $this->counter($metric, $context);
    }

    /**
     * Record render characteristics without accepting the rendered contents.
     * Exact byte counts are intentionally kept out of operational logs; the
     * benchmark report may retain exact measurements separately.
     *
     * @param  array<string, int>  $queryStats
     */
    public function txtRender(
        TelegramOutboundEffect $effect,
        float $durationMs,
        ?int $outputBytes,
        int $workerRssBeforeBytes,
        int $workerRssAfterBytes,
        array $queryStats = [],
        string $outcome = 'ok',
    ): void {
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'failed';
        $context = $this->metadata($effect) + [
            'outcome' => $outcome,
            'render_duration_ms' => $this->milliseconds($durationMs),
            'render_duration_bucket' => $this->durationBucket($durationMs),
            'output_size_bucket' => $this->byteSizeBucket($outputBytes),
            'worker_rss_before_bytes' => max(0, $workerRssBeforeBytes),
            'worker_rss_after_bytes' => max(0, $workerRssAfterBytes),
            'worker_rss_delta_bucket' => $this->memoryDeltaBucket($workerRssBeforeBytes, $workerRssAfterBytes),
        ];

        foreach (['db_query_count', 'db_query_total_ms', 'db_query_slowest_ms'] as $key) {
            if (array_key_exists($key, $queryStats)) {
                $context[$key] = max(0, (int) round((float) $queryStats[$key]));
            }
        }

        DogfoodTiming::log(self::RENDER_EVENT, $context);
    }

    public function txtDocumentAttempt(
        TelegramOutboundEffect $effect,
        float $durationMs,
        string $outcome,
    ): void {
        $outcome = in_array($outcome, self::OUTCOMES, true) ? $outcome : 'failed';

        DogfoodTiming::log(self::DOCUMENT_EVENT, $this->metadata($effect) + [
            'outcome' => $outcome,
            'telegram_document_duration_ms' => $this->milliseconds($durationMs),
            'telegram_document_duration_bucket' => $this->durationBucket($durationMs),
        ]);
    }

    public function queueWaitBucket(?int $queueWaitMs): ?string
    {
        return $queueWaitMs === null ? null : $this->durationBucket($queueWaitMs);
    }

    public function byteSizeBucket(?int $bytes): string
    {
        if ($bytes === null || $bytes < 0) {
            return 'unknown';
        }

        return match (true) {
            $bytes <= 1024 => '0-1024',
            $bytes <= 16_384 => '1025-16384',
            $bytes <= 65_536 => '16385-65536',
            $bytes <= 262_144 => '65537-262144',
            $bytes <= 1_048_576 => '262145-1048576',
            default => 'over-limit',
        };
    }

    public function durationBucket(float|int|null $milliseconds): string
    {
        if ($milliseconds === null || $milliseconds < 0) {
            return 'unknown';
        }

        return match (true) {
            $milliseconds <= 10 => '0-10',
            $milliseconds <= 50 => '11-50',
            $milliseconds <= 100 => '51-100',
            $milliseconds <= 250 => '101-250',
            $milliseconds <= 500 => '251-500',
            $milliseconds <= 1000 => '501-1000',
            $milliseconds <= 2000 => '1001-2000',
            $milliseconds <= 5000 => '2001-5000',
            default => 'over-5000',
        };
    }

    public function countBucket(mixed $count): string
    {
        if (filter_var($count, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            return 'unknown';
        }

        $count = (int) $count;

        return match (true) {
            $count === 0 => '0',
            $count === 1 => '1',
            $count <= 5 => '2-5',
            $count <= 10 => '6-10',
            $count <= 20 => '11-20',
            $count <= 50 => '21-50',
            default => 'over-50',
        };
    }

    private function counter(string $metric, array $context): void
    {
        if (! in_array($metric, self::COUNTERS, true)) {
            return;
        }

        DogfoodTiming::log(self::COUNTER_EVENT, [
            'metric' => $metric,
            'count' => 1,
            ...$context,
        ]);
    }

    /** @return array<string, mixed> */
    private function metadata(
        ?TelegramOutboundEffect $effect = null,
        ?Customer $customer = null,
        ?Order $order = null,
        ?string $callbackQueryId = null,
    ): array {
        $orderId = $order?->getKey() ?? $effect?->source_id;
        $metadata = array_filter([
            'effect_correlation_id' => DogfoodTiming::correlation($effect?->getKey()),
            'order_correlation_id' => DogfoodTiming::correlation($orderId),
            'customer_correlation_id' => DogfoodTiming::correlation($customer?->getKey()),
            'callback_correlation_id' => DogfoodTiming::correlation($callbackQueryId),
        ], static fn (mixed $value): bool => $value !== null);

        if ($effect !== null) {
            $effectMetadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];
            $metadata['product_count_bucket'] = $this->countBucket($effectMetadata['product_count'] ?? null);
            $metadata['item_count_bucket'] = $this->countBucket($effectMetadata['item_count'] ?? null);
        }

        return $metadata;
    }

    private function reason(string $reason): string
    {
        return in_array($reason, self::EFFECT_REASONS, true) ? $reason : 'other';
    }

    private function milliseconds(float $milliseconds): float
    {
        return round(max(0, $milliseconds), 2);
    }

    private function memoryDeltaBucket(int $before, int $after): string
    {
        $delta = max(0, $after - $before);

        return match (true) {
            $delta <= 1_048_576 => '0-1MiB',
            $delta <= 4_194_304 => '1-4MiB',
            $delta <= 16_777_216 => '4-16MiB',
            $delta <= 25_165_824 => '16-24MiB',
            default => 'over-24MiB',
        };
    }
}
