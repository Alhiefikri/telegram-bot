<?php

declare(strict_types=1);

namespace App\Services\TelegramStars;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use DomainException;

final class StarsQuoteCalculator
{
    // Conservative application ceiling for one-time invoices; not a claim
    // about Telegram's server-side limit for this invoice type.
    public const MAX_INVOICE_XTR = 10_000;

    /**
     * Convert the final server-priced retail total, never per item or unit.
     * This is pricing only: preview is allowed even when issuance is off.
     */
    public function quote(int $totalIdr, StarsPricingPolicy $policy): int
    {
        if ($totalIdr < 1) {
            throw new DomainException('Total retail harus berupa rupiah bulat yang lebih besar dari nol.');
        }

        if ($policy->idrPerUsd === null || $policy->reserveBps === null) {
            throw new DomainException('Kurs dan cadangan Telegram Stars belum diatur.');
        }

        // Keep both products exact, including when either exceeds native int.
        $numerator = BigInteger::of($totalIdr)
            ->multipliedBy(1_000_000)
            ->multipliedBy(StarsPricingPolicy::BASIS_POINTS);
        $denominator = BigInteger::of($policy->idrPerUsd)
            ->multipliedBy($policy->rewardMicroUsd)
            ->multipliedBy(StarsPricingPolicy::BASIS_POINTS - $policy->reserveBps);

        $totalXtr = $numerator->dividedBy($denominator, RoundingMode::Ceiling);

        if ($totalXtr->isGreaterThan(self::MAX_INVOICE_XTR)) {
            throw new DomainException('Total melebihi batas '.self::MAX_INVOICE_XTR.' XTR per invoice.');
        }

        return $totalXtr->toInt();
    }
}
