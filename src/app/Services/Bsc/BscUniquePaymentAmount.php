<?php

declare(strict_types=1);

namespace App\Services\Bsc;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the catalog amount when free, then tries 0.001-USDT steps.
 * Checkout holds the payment-setting row lock across allocation and insert.
 * Reserve amounts the verifier can still associate with another invoice.
 */
final class BscUniquePaymentAmount
{
    private const INCREMENT_MICRO = 1_000;

    private const CANDIDATE_BATCH_SIZE = 1_000;

    public function allocate(int $baseAmountMicro): int
    {
        $increment = self::INCREMENT_MICRO;
        if ($baseAmountMicro < 1) {
            return $baseAmountMicro;
        }

        $recent = now()->subHours(BscInvoiceAttribution::LOOKBACK_HOURS);
        for ($rangeStart = $baseAmountMicro; ; $rangeStart += self::CANDIDATE_BATCH_SIZE * $increment) {
            $rangeEnd = $rangeStart + ((self::CANDIDATE_BATCH_SIZE - 1) * $increment);
            $occupied = Payment::query()
                ->where('provider', 'manual')
                ->where('method', 'USDT_BEP20')
                ->where('currency', 'USDT')
                ->where(fn ($query) => $query->where('created_at', '>=', $recent)->orWhere('status', PaymentStatus::Pending->value))
                ->whereHas('bscDetail')
                ->whereBetween('amount', [$rangeStart, $rangeEnd])
                ->orderBy('amount')
                ->pluck('amount')
                ->mapWithKeys(fn (int|string $amount): array => [(int) $amount => true])
                ->all();

            $intents = DB::table('customer_usdt_funding_intents')
                ->where('rail', 'bsc_shared')
                ->where(fn ($query) => $query->where('created_at', '>=', $recent)->orWhere('state', 'pending'))
                ->whereBetween('payable_micro', [$rangeStart, $rangeEnd])->pluck('payable_micro');
            $topUps = DB::table('customer_usdt_top_ups as targets')
                ->leftJoin('customer_usdt_top_up_resolutions as resolutions', 'resolutions.top_up_id', '=', 'targets.id')
                ->where('targets.rail', 'bsc_shared')
                ->where(fn ($query) => $query->where('targets.created_at', '>=', $recent)->orWhereNull('resolutions.top_up_id'))
                ->whereBetween('targets.payable_micro', [$rangeStart, $rangeEnd])->pluck('targets.payable_micro');
            foreach ($intents->merge($topUps) as $amount) {
                $occupied[(int) $amount] = true;
            }

            for ($candidate = $rangeStart; $candidate <= $rangeEnd; $candidate += $increment) {
                if (! isset($occupied[$candidate])) {
                    return $candidate;
                }
            }
        }
    }
}
