<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class PaymentLifecycleRolloutReport
{
    /** @return array<string, int|array<string, int>> */
    public function unresolved(): array
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
                'stock_expired' => DB::table('stock_notification_recipients')
                    ->whereNull('sent_at')->whereNull('terminal_failed_at')->whereNotNull('claim_expires_at')->where('claim_expires_at', '<=', now())->count(),
                'broadcast_stale' => DB::table('telegram_broadcast_recipients')
                    ->whereNull('sent_at')->whereNull('terminal_failed_at')->whereNotNull('send_started_at')->where('send_started_at', '<=', $staleClaimBefore)->count(),
                'legacy_stock_unmaterialized' => DB::table('inventory_movements as movement')
                    ->leftJoin('stock_notification_events as event', 'event.inventory_movement_id', '=', 'movement.id')
                    ->whereNotNull('movement.stock_notification_started_at')->whereNull('movement.stock_notification_sent_at')
                    ->whereNull('event.id')->count(),
            ],
            'lifecycle_state_mismatches' => DB::table('payments')
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->whereColumn('orders.payment_status', '!=', 'payments.status')->count(),
        ];
    }

    /** @return array<string, mixed> */
    public function summary(PaymentLifecycleRollout $rollout): array
    {
        $unresolved = $this->unresolved();
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
            'contract_safe' => $total === 0,
            'unresolved_total' => $total,
        ];
    }
}
