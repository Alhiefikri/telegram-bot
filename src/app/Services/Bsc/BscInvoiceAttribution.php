<?php

namespace App\Services\Bsc;

use Brick\Math\BigInteger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class BscInvoiceAttribution
{
    public static function matches(string $actual, string $expected, int $decimals): bool
    {
        if ($decimals < 1) {
            return false;
        }

        return BigInteger::of($actual)->minus($expected)->abs()
            ->isLessThanOrEqualTo(BigInteger::of(10)->power($decimals - 1));
    }

    public function hasConflict(
        string $receiver,
        string $contract,
        string $amountUnits,
        string $expectedAmountUnits,
        int $decimals,
        Carbon $blockTimestamp,
        ?string $paymentId = null,
        ?string $topUpIntentId = null,
    ): bool {
        $exactTarget = BigInteger::of($amountUnits)->isEqualTo($expectedAmountUnits);
        $at = $blockTimestamp->copy()->setTimezone(config('app.timezone'));
        $recent = $at->copy()->subHours(24);
        $receiver = strtolower($receiver);
        $contract = strtolower($contract);

        $checkouts = DB::table('payments as payments')
            ->join('payment_bsc_details as details', 'details.payment_id', '=', 'payments.id')
            ->where('payments.provider', 'manual')->where('payments.method', 'USDT_BEP20')
            ->where('payments.currency', 'USDT')->where('payments.created_at', '<=', $at)
            ->where(fn ($q) => $q->where('payments.created_at', '>=', $recent)->orWhere('payments.status', 'pending'))
            ->whereRaw('LOWER(details.receiver_address) = ?', [$receiver])
            ->whereRaw('LOWER(details.token_contract) = ?', [$contract])
            ->when($paymentId !== null, fn ($q) => $q->where('payments.id', '<>', $paymentId))
            ->get(['details.expected_token_units', 'details.token_decimals']);
        foreach ($checkouts as $checkout) {
            if ((int) $checkout->token_decimals === $decimals
                && $this->competes($amountUnits, (string) $checkout->expected_token_units, $decimals, $exactTarget)) {
                return true;
            }
        }

        $intents = DB::table('customer_usdt_funding_intents')
            ->where('rail', 'bsc_shared')->where('created_at', '<=', $at)
            ->where(fn ($q) => $q->where('created_at', '>=', $recent)->orWhere('state', 'pending'))
            ->when($topUpIntentId !== null, fn ($q) => $q->where('id', '<>', $topUpIntentId))
            ->get(['payable_micro', 'payment_snapshot']);
        $topUps = DB::table('customer_usdt_top_ups as targets')
            ->leftJoin('customer_usdt_top_up_resolutions as resolutions', 'resolutions.top_up_id', '=', 'targets.id')
            ->where('targets.rail', 'bsc_shared')->where('targets.created_at', '<=', $at)
            ->where(fn ($q) => $q->where('targets.created_at', '>=', $recent)->orWhereNull('resolutions.top_up_id'))
            ->when($topUpIntentId !== null, fn ($q) => $q->where('targets.intent_id', '<>', $topUpIntentId))
            ->get(['targets.payable_micro', 'targets.payment_snapshot']);
        foreach ($intents->concat($topUps) as $invoice) {
            $snapshot = json_decode((string) $invoice->payment_snapshot, true);
            if (! is_array($snapshot)
                || strtolower((string) ($snapshot['receiver_address'] ?? '')) !== $receiver
                || strtolower((string) ($snapshot['token_contract'] ?? '')) !== $contract
                || (int) ($snapshot['token_decimals'] ?? -1) !== $decimals
                || $decimals < 6) {
                continue;
            }
            $expected = BigInteger::of((string) $invoice->payable_micro)->multipliedBy(BigInteger::of(10)->power($decimals - 6));
            if ($this->competes($amountUnits, (string) $expected, $decimals, $exactTarget)) {
                return true;
            }
        }

        return false;
    }

    private function competes(string $actual, string $expected, int $decimals, bool $exactTarget): bool
    {
        return $exactTarget
            ? BigInteger::of($actual)->isEqualTo($expected)
            : self::matches($actual, $expected, $decimals);
    }
}
