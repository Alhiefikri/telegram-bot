<?php

namespace App\Services\TelegramStars;

use App\Enums\PaymentStatus;
use App\Models\TelegramUpdate;
use App\Services\Telegram\TelegramStarsUpdate;
use Illuminate\Support\Facades\DB;

final class TelegramStarsOperations
{
    public function counts(): array
    {
        $refunds = DB::table('telegram_stars_refunds');
        $settlements = DB::table('telegram_stars_settlements');
        $resolved = (clone $refunds)->where('state', 'confirmed');
        $unresolved = (clone $refunds)->whereIn('state', ['pending', 'review']);
        $drift = (clone $refunds)->join('payments', 'payments.id', '=', 'telegram_stars_refunds.payment_id')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereRaw('CASE WHEN telegram_stars_refunds.state = ? THEN (payments.status <> ? OR orders.payment_status <> ?)
                ELSE (payments.status <> ? OR orders.payment_status <> ?) END', [
                'confirmed', PaymentStatus::Refunded->value, PaymentStatus::Refunded->value, PaymentStatus::Paid->value, PaymentStatus::Paid->value,
            ])->count();

        return [
            'unprocessed_inbox' => TelegramUpdate::whereIn('stars_event', [TelegramStarsUpdate::SUCCESS, TelegramStarsUpdate::REFUND])
                ->whereNull('processed_at')->count(),
            'late_settlements' => (clone $settlements)->where('late', true)->count(),
            'payment_review_records' => DB::table('telegram_stars_payment_reviews')->count(),
            'refund_review_records' => DB::table('telegram_stars_refund_reviews')->count(),
            'unresolved_refunds' => (clone $unresolved)->count(),
            'refunds_over_5_min' => (clone $unresolved)->where('requested_at', '<=', now()->subMinutes(5))->count(),
            'refund_state_drift' => $drift,
            'original_settled_xtr' => $settlements->join('payment_telegram_stars_details as target', 'target.payment_id', '=', 'telegram_stars_settlements.payment_id')
                ->sum('target.total_xtr'),
            'confirmed_refunded_xtr' => $resolved->join('payment_telegram_stars_details as target', 'target.payment_id', '=', 'telegram_stars_refunds.payment_id')
                ->sum('target.total_xtr'),
        ];
    }
}
