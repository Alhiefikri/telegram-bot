<?php

namespace App\Services\Bsc;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentBscDetail;

final class BscOperationalMetrics
{
    /** @return array{pending: int, oldest_age_seconds: int, oldest_created_at: ?string} */
    public function pendingVerification(): array
    {
        try {
            $query = Payment::query()
                ->where('provider', 'manual')
                ->where('method', 'USDT_BEP20')
                ->where('status', PaymentStatus::Pending->value)
                ->whereHas('bscDetail', fn ($detail) => $detail
                    ->where(function ($mode): void {
                        $mode
                            ->where('verification_mode', PaymentBscDetail::VERIFICATION_MODE_AMOUNT_UNIQUE)
                            ->orWhere(function ($legacy): void {
                                $legacy
                                    ->where('verification_mode', PaymentBscDetail::VERIFICATION_MODE_TRANSACTION_HASH)
                                    ->whereNotNull('current_tx_hash');
                            });
                    })
                    ->whereNotIn('verification_status', [
                        'confirmed',
                        'expired',
                        'manual_review',
                        'mismatch',
                        'transaction_already_used',
                    ]));

            $count = (int) $query->count();
            $oldest = $count > 0 ? $query->min('created_at') : null;
        } catch (\Throwable) {
            return [
                'pending' => 0,
                'oldest_age_seconds' => 0,
                'oldest_created_at' => null,
            ];
        }

        $oldestTimestamp = $oldest === null ? null : strtotime((string) $oldest);

        return [
            'pending' => $count,
            'oldest_age_seconds' => $oldestTimestamp === false || $oldestTimestamp === null
                ? 0
                : max(0, now()->timestamp - $oldestTimestamp),
            'oldest_created_at' => $oldest === null ? null : (string) $oldest,
        ];
    }
}
