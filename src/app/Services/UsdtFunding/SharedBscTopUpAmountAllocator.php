<?php

namespace App\Services\UsdtFunding;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Allocates an exact payable amount for a shared-address BSC invoice.
 *
 * The preferred amount is the Customer's requested base. When another open
 * invoice already holds that exact amount, a bounded micro-USDT suffix is added
 * (at most 1% of the base) so concurrent Customers never share one payable
 * amount. The caller holds the payment-setting row lock shared with checkout
 * across allocation and intent insertion.
 */
final class SharedBscTopUpAmountAllocator
{
    private const MAX_SUFFIX_MICRO = 999_999;

    private const LOOKBACK_HOURS = 24;

    public function allocate(int $baseMicro, int $feeMicro = 0): int
    {
        if ($baseMicro < 1 || $feeMicro < 0 || $feeMicro > PHP_INT_MAX - $baseMicro) {
            throw new RuntimeException('Shared BSC top-up amount and fee must fit positive integer micro-USDT.');
        }

        // Keep the matching suffix under 1% of the requested amount, with a
        // small floor so low-value top-ups still have a usable tag range.
        $maxSuffix = min(PHP_INT_MAX - $baseMicro - $feeMicro, max(9, min(self::MAX_SUFFIX_MICRO, intdiv($baseMicro, 100))));
        $minimumCreatedAt = now()->subHours(self::LOOKBACK_HOURS);

        $occupied = $this->occupiedAmounts($baseMicro + $feeMicro, $baseMicro + $feeMicro + $maxSuffix, $minimumCreatedAt);
        for ($suffix = 0; $suffix <= $maxSuffix; $suffix++) {
            $candidate = $baseMicro + $suffix;
            if (! isset($occupied[$candidate + $feeMicro])) {
                return $candidate;
            }
        }

        throw new RuntimeException('No unique shared BSC top-up amount is available for this request.');
    }

    /** @return array<int, true> */
    private function occupiedAmounts(int $fromMicro, int $toMicro, \DateTimeInterface $minimumCreatedAt): array
    {
        $intents = DB::table('customer_usdt_funding_intents')
            ->where('rail', 'bsc_shared')
            ->where('state', 'pending')
            ->where('expires_at', '>', now())
            ->whereBetween('payable_micro', [$fromMicro, $toMicro])
            ->pluck('payable_micro');

        $topUps = DB::table('customer_usdt_top_ups')
            ->where('rail', 'bsc_shared')
            ->where('created_at', '>=', $minimumCreatedAt)
            ->whereBetween('payable_micro', [$fromMicro, $toMicro])
            ->pluck('payable_micro');

        $checkouts = DB::table('payments')
            ->where('provider', 'manual')->where('method', 'USDT_BEP20')->where('currency', 'USDT')
            ->where(fn ($query) => $query->where('created_at', '>=', $minimumCreatedAt)->orWhere('status', 'pending'))
            ->whereBetween('amount', [$fromMicro, $toMicro])
            ->pluck('amount');

        $occupied = [];
        foreach ($intents->merge($topUps)->merge($checkouts) as $amount) {
            $occupied[(int) $amount] = true;
        }

        return $occupied;
    }
}
