<?php

namespace App\Services\Telegram\Retention;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\TelegramBroadcast;
use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Expression;
use Throwable;

final class NotificationRetentionClassifier
{
    public const EFFECT_COLUMNS = ['id', 'effect_type', 'source_type', 'source_id', 'effect_group_key', 'supersedes_effect_id', 'state', 'attempts', 'last_error_code', 'next_attempt_at', 'lease_until', 'created_at', 'updated_at', 'sent_at', 'failed_at', 'unknown_at', 'manual_review_at', 'telegram_message_deletion_started_at', 'telegram_message_deleted_at'];

    public const SPECS = [
        'supplier-health' => ['supplier_health_events', 'SupplierHealthEvent', ['id', 'created_at'], null, null],
        'stock-events' => ['stock_notification_events', 'StockNotificationEvent', ['id', 'inventory_movement_id', 'recipients_snapshotted_at', 'materialization_cursor', 'created_at', 'updated_at'], 'stock-recipients', 'recipients_snapshotted_at'],
        'stock-recipients' => ['stock_notification_recipients', 'StockNotificationRecipient', ['id', 'stock_notification_event_id', 'sent_at', 'failed_at', 'terminal_failed_at', 'created_at', 'updated_at'], 'stock-events', 'stock_notification_event_id'],
        'price-events' => ['product_price_change_events', 'ProductPriceChangeEvent', ['id', 'recipients_materialized_at', 'recipient_effect_cursor', 'store_channel_sent_at', 'store_channel_failed_at', 'created_at', 'updated_at'], 'price-recipients', 'recipients_materialized_at'],
        'price-recipients' => ['product_price_change_notification_recipients', 'ProductPriceChangeNotificationRecipient', ['id', 'event_id', 'sent_at', 'failed_at', 'created_at', 'updated_at'], 'price-events', 'event_id'],
        'broadcasts' => ['telegram_broadcasts', 'TelegramBroadcast', ['id', 'status', 'recipient_count', 'pending_count', 'recipients_materialized_at', 'completed_at', 'created_at', 'updated_at'], 'broadcast-recipients', 'recipients_materialized_at'],
        'broadcast-recipients' => ['telegram_broadcast_recipients', 'TelegramBroadcastRecipient', ['id', 'broadcast_id', 'sent_at', 'failed_at', 'terminal_failed_at', 'created_at', 'updated_at'], 'broadcasts', 'broadcast_id'],
    ];

    private const NOTIFICATIONS = ['admin.supplier_health', 'stock.notification_recipient', 'price_change.notification_recipient', 'price_change.store_channel', 'admin.broadcast_recipient'];

    private const REASONS = ['unsupported_family', 'unsupported_state', 'financial_or_credential_history', 'active_obligation', 'missing_source', 'missing_effect', 'partial_audience', 'empty_audience', 'projection_incomplete', 'ambiguity', 'manual_review', 'retryable_failure', 'future_due', 'manual_retry_possible', 'required_reference', 'replay_possible', 'unclassified_supplier_event', 'unresolved_supplier_episode', 'hold_unproven', 'archive_unverified', 'reference_unproven', 'dependency_budget_exceeded', 'age_unproven', 'too_recent'];

    public function __construct(private readonly TelegramOutboundEffectPolicy $policy) {}

    public function columns(string $family): array
    {
        return $family === 'effects' ? self::effectColumns() : self::SPECS[$family][2];
    }

    public static function effectColumns(): array
    {
        return [...self::EFFECT_COLUMNS, new Expression('claim_token IS NOT NULL AS has_claim')];
    }

    public function evaluate(string $family, object $root, RetentionWindow $window, RetentionReader $reader): array
    {
        // No indexed, complete reverse metadata/audit/hold proof or qualified archive
        // exists in this schema. Do not turn their absence into negative evidence.
        $reasons = ['reference_unproven', 'hold_unproven', 'archive_unverified', 'age_unproven'];
        $queue = [[$family, $root]];
        $seen = [];
        $latest = null;
        $invalidAge = false;
        $terminal = true;
        $rootState = 'unproven';
        while ($queue !== [] && ! $reader->truncated) {
            $reader->check();
            [$kind, $row] = $queue[array_key_first($queue)];
            unset($queue[array_key_first($queue)]);
            $identity = $kind.':'.$row->id;
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $this->activity($row, $window, $latest, $invalidAge);
            if ($kind === 'effects') {
                $state = (string) $row->state;
                if ($kind === $family && $row->id === $root->id) {
                    $rootState = TelegramOutboundEffectState::tryFrom($state)?->value ?? 'unsupported';
                }
                $type = TelegramOutboundEffectType::tryFrom((string) $row->effect_type);
                if ($type === null) {
                    $reasons[] = 'unsupported_family';
                } elseif (! in_array($type->value, self::NOTIFICATIONS, true)) {
                    $reasons[] = 'financial_or_credential_history';
                }
                if (TelegramOutboundEffectState::tryFrom($state) === null) {
                    $reasons[] = 'unsupported_state';
                    $terminal = false;
                } else {
                    // Scalar-only in-memory model: no retrieval, payload cast, event or save.
                    $effect = new TelegramOutboundEffect;
                    $effect->setRawAttributes((array) $row, true);
                    if (in_array($state, ['pending', 'sending'], true) || $row->lease_until !== null || ($row->has_claim ?? false)) {
                        $reasons[] = 'active_obligation';
                        $terminal = false;
                    }
                    if ($state === 'unknown') {
                        $reasons[] = 'ambiguity';
                        $terminal = false;
                    }
                    if ($state === 'manual_review') {
                        $reasons[] = 'manual_review';
                        $terminal = false;
                    }
                    if ($row->manual_review_at !== null || $row->unknown_at !== null) {
                        $reasons[] = 'required_reference';
                    }
                    if ($state === 'failed' && ! $this->policy->isTerminalFailure($effect)) {
                        $reasons[] = 'retryable_failure';
                        $terminal = false;
                    }
                    if ($this->policy->canRequestManualRetry($effect)) {
                        $reasons[] = 'manual_retry_possible';
                        $terminal = false;
                    }
                    if ($row->next_attempt_at !== null && CarbonImmutable::parse($row->next_attempt_at, $window->timezone)->gt($window->asOf)) {
                        $reasons[] = 'future_due';
                    }
                    if ($row->telegram_message_deletion_started_at !== null && $row->telegram_message_deleted_at === null) {
                        $reasons[] = 'projection_incomplete';
                    }
                }
                $table = $reader->db->table('telegram_outbound_effects');
                if ($row->effect_group_key !== null) {
                    $this->enqueue($queue, 'effects', $reader->rows((clone $table)->where('effect_group_key', $row->effect_group_key)->orderBy('sequence'), self::EFFECT_COLUMNS));
                }
                $this->enqueue($queue, 'effects', $reader->rows((clone $table)->where('supersedes_effect_id', $row->id), self::EFFECT_COLUMNS));
                if ($row->supersedes_effect_id !== null) {
                    $reasons[] = 'required_reference';
                    $parents = $reader->rows((clone $table)->where('id', $row->supersedes_effect_id), self::EFFECT_COLUMNS);
                    if ($parents === []) {
                        $reasons[] = 'reference_unproven';
                    }
                    $this->enqueue($queue, 'effects', $parents);
                }
                $sourceKind = null;
                foreach (self::SPECS as $candidate => $spec) {
                    if ($row->source_type === 'App\\Models\\'.$spec[1]) {
                        $sourceKind = $candidate;
                        break;
                    }
                }
                if ($sourceKind === null) {
                    $reasons[] = 'unsupported_family';
                } else {
                    $spec = self::SPECS[$sourceKind];
                    // Reject corrupt polymorphic keys before PostgreSQL can emit bindings.
                    $uuid = $sourceKind === 'supplier-health';
                    if ($uuid ? ! preg_match('/^[0-9a-f-]{36}$/D', $row->source_id) : ! preg_match('/^[1-9][0-9]{0,17}$/D', $row->source_id)) {
                        $reasons[] = 'missing_source';
                    } else {
                        $sources = $reader->rows($reader->db->table($spec[0])->where('id', $row->source_id), $spec[2]);
                        if ($sources === []) {
                            $reasons[] = 'missing_source';
                        }
                        $this->enqueue($queue, $sourceKind, $sources);
                        if ($state === 'sent' && isset($sources[0]) && property_exists($sources[0], 'sent_at') && $sources[0]->sent_at === null) {
                            $reasons[] = 'projection_incomplete';
                        }
                        if ($state === 'sent' && $sourceKind === 'price-events' && isset($sources[0]) && $sources[0]->store_channel_sent_at === null) {
                            $reasons[] = 'projection_incomplete';
                        }
                    }
                }

                continue;
            }
            $spec = self::SPECS[$kind];
            $reasons[] = 'replay_possible';
            if ($kind === 'supplier-health') {
                $reasons[] = 'unclassified_supplier_event';
                $reasons[] = 'unresolved_supplier_episode';
            }
            if ($kind === 'broadcasts') {
                $broadcast = TelegramBroadcast::class;
                if (! in_array($row->status, [$broadcast::STATUS_QUEUED, $broadcast::STATUS_IN_PROGRESS, $broadcast::STATUS_COMPLETED, $broadcast::STATUS_PARTIALLY_FAILED, $broadcast::STATUS_FAILED, $broadcast::STATUS_SKIPPED], true)) {
                    $reasons[] = 'unsupported_state';
                }
                if (in_array($row->status, [$broadcast::STATUS_QUEUED, $broadcast::STATUS_IN_PROGRESS], true) || (int) $row->pending_count > 0) {
                    $reasons[] = 'active_obligation';
                    $terminal = false;
                }
            }
            if ($kind === 'stock-events') {
                $movements = $reader->rows($reader->db->table('inventory_movements')->where('id', $row->inventory_movement_id), ['id', 'created_at', 'stock_notification_sent_at']);
                if ($movements === []) {
                    $reasons[] = 'missing_source';
                } else {
                    $this->activity($movements[0], $window, $latest, $invalidAge);
                    if ($movements[0]->stock_notification_sent_at === null) {
                        $reasons[] = 'projection_incomplete';
                    }
                }
            }
            $effects = $reader->rows($reader->db->table('telegram_outbound_effects')->where('source_type', 'App\\Models\\'.$spec[1])->where('source_id', (string) $row->id)->orderBy('effect_type'), self::EFFECT_COLUMNS);
            if ($effects === [] && ! in_array($kind, ['stock-events', 'broadcasts'], true)) {
                $reasons[] = 'missing_effect';
                $reasons[] = 'active_obligation';
                $terminal = false;
            }
            $this->enqueue($queue, 'effects', $effects);
            if ($spec[3] !== null) {
                $related = self::SPECS[$spec[3]];
                $isEvent = in_array($kind, ['stock-events', 'price-events', 'broadcasts'], true);
                if ($isEvent) {
                    if ($row->{$spec[4]} === null) {
                        $reasons[] = 'partial_audience';
                        $reasons[] = 'active_obligation';
                        $terminal = false;
                    }
                    $childrenQuery = $reader->db->table($related[0])->where($related[4], $row->id);
                    if ($kind === 'broadcasts') {
                        $childrenQuery->orderBy('terminal_failed_at');
                    } else {
                        $childrenQuery->orderBy('id');
                    }
                    $children = $reader->rows($childrenQuery, $related[2]);
                    if ($kind === 'broadcasts' && ! $reader->truncated && (int) $row->recipient_count !== count($children)) {
                        $reasons[] = 'projection_incomplete';
                    }
                    if ($children === []) {
                        $terminal = false;
                        $reasons[] = 'empty_audience';
                    }
                    if ($children !== [] && in_array($kind, ['stock-events', 'price-events'], true)) {
                        $cursor = $kind === 'stock-events' ? $row->materialization_cursor : $row->recipient_effect_cursor;
                        if ($cursor === null || (int) $cursor < max(array_column($children, 'id'))) {
                            $reasons[] = 'active_obligation';
                            $terminal = false;
                        }
                    }
                    $this->enqueue($queue, $spec[3], $children);
                } else {
                    $parents = $reader->rows($reader->db->table($related[0])->where('id', $row->{$spec[4]}), $related[2]);
                    if ($parents === []) {
                        $reasons[] = 'missing_source';
                    }
                    $this->enqueue($queue, $spec[3], $parents);
                }
            }
        }
        if ($reader->truncated) {
            $reasons[] = 'dependency_budget_exceeded';
            $terminal = false;
        }
        $age = 'unproven';
        if (! $invalidAge && $latest !== null) {
            $age = $latest->gt($window->asOf->subDays($window->horizon)) ? 'too_recent' : 'at_or_beyond_scenario';
            if ($age === 'too_recent') {
                $reasons[] = 'too_recent';
            }
        }
        $ordered = array_values(array_intersect(self::REASONS, array_unique($reasons)));

        return ['family' => $family === 'effects' ? (in_array($root->effect_type, self::NOTIFICATIONS, true) ? $root->effect_type : 'protected_other') : $family, 'state' => $rootState, 'age_bucket' => $age, 'state_only_terminal' => $terminal, 'classification' => 'protected', 'reasons' => $ordered];
    }

    private function enqueue(array &$queue, string $kind, array $rows): void
    {
        foreach ($rows as $row) {
            $queue[] = [$kind, $row];
        }
    }

    private function activity(object $row, RetentionWindow $window, ?CarbonImmutable &$latest, bool &$invalid): void
    {
        if (! isset($row->created_at) || (property_exists($row, 'updated_at') && $row->updated_at === null)) {
            $invalid = true;
        }
        foreach ((array) $row as $key => $value) {
            if (! str_ends_with($key, '_at') || $value === null) {
                continue;
            }
            try {
                $date = CarbonImmutable::parse($value, $window->timezone);
                if ($date->gt($window->asOf) || (isset($row->created_at) && $date->lt(CarbonImmutable::parse($row->created_at, $window->timezone)))) {
                    $invalid = true;
                }
                $latest = $latest === null || $date->gt($latest) ? $date : $latest;
            } catch (Throwable) {
                $invalid = true;
            }
        }
        if (isset($row->state) && in_array($row->state, ['sent', 'failed', 'unknown', 'manual_review'], true)) {
            $stamp = ['sent' => 'sent_at', 'failed' => 'failed_at', 'unknown' => 'unknown_at', 'manual_review' => 'manual_review_at'][$row->state];
            if ($row->{$stamp} === null) {
                $invalid = true;
            }
        }
    }
}
