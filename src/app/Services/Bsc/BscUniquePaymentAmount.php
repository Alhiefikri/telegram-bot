<?php

namespace App\Services\Bsc;

use App\Enums\PaymentStatus;
use App\Exceptions\InvalidCheckout;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;

final class BscUniquePaymentAmount
{
    public const DEFAULT_INCREMENT_MICRO = 100;

    public const DEFAULT_MAX_CANDIDATES = 1_000;

    public function allocate(
        int $baseAmountMicro,
        ?int $incrementMicro = null,
        ?int $maxCandidates = null,
    ): int {
        if ($baseAmountMicro < 0) {
            throw new InvalidCheckout('BSC payment amount cannot be negative.');
        }

        $incrementMicro ??= ManualPaymentSetting::current()?->bscUniqueAmountIncrementMicro()
            ?? self::DEFAULT_INCREMENT_MICRO;
        $maxCandidates ??= (int) config('services.bsc.max_unique_amount_candidates', self::DEFAULT_MAX_CANDIDATES);

        if ($incrementMicro < 1) {
            throw new InvalidCheckout('BSC payment amount increment must be positive.');
        }
        if ($maxCandidates < 1) {
            throw new InvalidCheckout('BSC payment amount allocation window is exhausted.');
        }
        if ($maxCandidates > intdiv(PHP_INT_MAX - $baseAmountMicro, $incrementMicro) + 1) {
            throw new InvalidCheckout('BSC payment amount allocation window exceeds integer capacity.');
        }

        $lastCandidate = $baseAmountMicro + (($maxCandidates - 1) * $incrementMicro);
        $occupied = Payment::query()
            ->where('provider', 'manual')
            ->where('method', 'USDT_BEP20')
            ->where('status', PaymentStatus::Pending->value)
            ->whereBetween('amount', [$baseAmountMicro, $lastCandidate])
            ->pluck('amount')
            ->mapWithKeys(fn ($amount): array => [(int) $amount => true])
            ->all();

        for ($offset = 0; $offset < $maxCandidates; $offset++) {
            $candidate = $baseAmountMicro + ($offset * $incrementMicro);
            if (! isset($occupied[$candidate])) {
                return $candidate;
            }
        }

        throw new InvalidCheckout('No unique BSC payment amount is available in the allocation window.');
    }

    public function expectedTokenUnits(int $payableAmountMicro, int $tokenDecimals): string
    {
        if ($payableAmountMicro < 0) {
            throw new InvalidCheckout('BSC payment amount cannot be negative.');
        }
        if ($tokenDecimals < 6) {
            throw new InvalidCheckout('BSC token decimals cannot represent integer micro-USDT.');
        }
        if ($payableAmountMicro === 0) {
            return '0';
        }

        return (string) $payableAmountMicro.str_repeat('0', $tokenDecimals - 6);
    }
}
