<?php

namespace App\Services;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use Illuminate\Support\Facades\DB;

final class PaymentLifecycleRolloutReport
{
    private const LEGACY_STOCK_CANDIDATE_LIMIT = 1000;

    /** @return array<string, int|array<string, int>> */
    private function unresolved(int $legacyStockUnmaterialized): array
    {
        $staleClaimBefore = now()->subMinutes(max(1, (int) config('services.payment_lifecycle_rollout.stale_claim_minutes', 15)));
        $uncertainStates = ['creating', 'outcome_unknown', 'provider_total_pending', 'financial_integrity_failed'];

        return [
            'provider_uncertainty' => DB::table('payments')
                ->where('status', 'pending')->whereIn('provider_create_state', $uncertainStates)->count(),
            'stuck_pending_payments' => DB::table('payments')
                ->where('status', 'pending')->whereNotNull('expired_at')->where('expired_at', '<=', now())->count(),
            'late_payment_queue' => DB::table('manual_fulfillment_reviews')
                ->whereIn('state', ['pending', 'refund_uncertain'])->count(),
            'cancellation_attempts' => DB::table('provider_cancellation_attempts')
                ->whereIn('state', ['pending', 'processing', 'retryable_failure'])->count(),
            'notification_claims' => [
                'stock_expired' => TelegramOutboundEffect::query()
                    ->where('source_type', StockNotificationRecipient::class)
                    ->where('effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
                    ->where('state', TelegramOutboundEffectState::Sending->value)
                    ->whereNotNull('lease_until')
                    ->where('lease_until', '<=', now())
                    ->count(),
                'broadcast_stale' => TelegramOutboundEffect::query()
                    ->where('source_type', TelegramBroadcastRecipient::class)
                    ->where('effect_type', TelegramOutboundEffectType::AdminBroadcastRecipient->value)
                    ->where('state', TelegramOutboundEffectState::Sending->value)
                    ->whereNotNull('lease_until')
                    ->where('lease_until', '<=', $staleClaimBefore)
                    ->count(),
                'legacy_stock_unmaterialized' => $legacyStockUnmaterialized,
            ],
            'lifecycle_state_mismatches' => DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->whereColumn('orders.payment_status', '!=', 'payments.status')->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(PaymentLifecycleRollout $rollout, bool $exactLegacyStock = false): array
    {
        $legacyStock = $this->legacyStockObservation($exactLegacyStock);
        $unresolved = $this->unresolved($legacyStock['unmaterialized']);
        $notificationClaims = $unresolved['notification_claims'];
        $total = (int) $unresolved['provider_uncertainty']
            + (int) $unresolved['stuck_pending_payments']
            + (int) $unresolved['late_payment_queue']
            + (int) $unresolved['cancellation_attempts']
            + (int) $unresolved['lifecycle_state_mismatches']
            + array_sum($notificationClaims);

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'rollout' => $rollout->status(),
            'unresolved' => $unresolved,
            'legacy_stock_coverage' => $legacyStock['coverage'],
            'contract_safe' => $legacyStock['coverage']['complete'] && $total === 0,
            'unresolved_total' => $total,
            'unresolved_total_is_exact' => $legacyStock['coverage']['complete'],
        ];
    }

    /** @return array{unmaterialized: int, coverage: array{mode: string, candidate_limit: ?int, candidates_scanned: ?int, complete: bool}} */
    private function legacyStockObservation(bool $exact): array
    {
        $missing = DB::table('stock_notification_recipients as recipient')
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects')
                    ->where('telegram_outbound_effects.source_type', StockNotificationRecipient::class)
                    ->where('telegram_outbound_effects.effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
                    ->whereRaw('telegram_outbound_effects.source_id = CAST(recipient.id AS TEXT)');
            });

        if ($exact) {
            return [
                'unmaterialized' => $missing->count(),
                'coverage' => [
                    'mode' => 'exact',
                    'candidate_limit' => null,
                    'candidates_scanned' => null,
                    'complete' => true,
                ],
            ];
        }

        // Bound source candidates before the anti-join; limiting missing rows still scans clean history.
        $candidates = DB::table('stock_notification_recipients')
            ->orderBy('id')
            ->limit(self::LEGACY_STOCK_CANDIDATE_LIMIT + 1)
            ->pluck('id');
        $complete = $candidates->count() <= self::LEGACY_STOCK_CANDIDATE_LIMIT;
        $candidateIds = $candidates->take(self::LEGACY_STOCK_CANDIDATE_LIMIT);

        return [
            'unmaterialized' => $missing->whereIn('recipient.id', $candidateIds)->count(),
            'coverage' => [
                'mode' => 'bounded',
                'candidate_limit' => self::LEGACY_STOCK_CANDIDATE_LIMIT,
                'candidates_scanned' => $candidateIds->count(),
                'complete' => $complete,
            ],
        ];
    }
}
