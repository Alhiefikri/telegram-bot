<?php

namespace App\Services\Bsc;

use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class BscInvoiceAttribution
{
    public const LOOKBACK_HOURS = 24;

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
        $actual = BigInteger::of($amountUnits);
        $exactTarget = $actual->isEqualTo($expectedAmountUnits);
        $tolerance = $exactTarget || $decimals < 1 ? BigInteger::zero() : BigInteger::of(10)->power($decimals - 1);
        $lower = BigInteger::max(0, $actual->minus($tolerance));
        $upper = $actual->plus($tolerance);
        $at = $blockTimestamp->copy()->setTimezone(config('app.timezone'));
        $recent = $at->copy()->subHours(self::LOOKBACK_HOURS);
        $receiver = strtolower($receiver);
        $contract = strtolower($contract);

        if (DB::table('payments as payments')
            ->join('payment_bsc_details as details', 'details.payment_id', '=', 'payments.id')
            ->where('payments.provider', 'manual')->where('payments.method', 'USDT_BEP20')
            ->where('payments.currency', 'USDT')->where('payments.created_at', '<=', $at)
            ->where(fn ($q) => $q->where('payments.created_at', '>=', $recent)->orWhere('payments.status', 'pending'))
            ->whereRaw('LOWER(details.receiver_address) = ?', [$receiver])
            ->whereRaw('LOWER(details.token_contract) = ?', [$contract])
            ->where('details.token_decimals', $decimals)
            ->whereBetween('details.expected_token_units', [(string) $lower, (string) $upper])
            ->when($paymentId !== null, fn ($q) => $q->where('payments.id', '<>', $paymentId))
            ->exists()) {
            return true;
        }

        if ($decimals < 6) {
            return false;
        }

        $unitsPerMicro = BigInteger::of(10)->power($decimals - 6);
        $minMicro = $lower->dividedBy($unitsPerMicro, RoundingMode::Ceiling);
        $maxMicro = BigInteger::min(PHP_INT_MAX, $upper->dividedBy($unitsPerMicro, RoundingMode::Floor));
        if ($minMicro->isGreaterThan($maxMicro)) {
            return false;
        }
        $microRange = [$minMicro->toInt(), $maxMicro->toInt()];

        $intents = DB::table('customer_usdt_funding_intents')
            ->where('rail', 'bsc_shared')->where('created_at', '<=', $at)
            ->where(fn ($q) => $q->where('created_at', '>=', $recent)->orWhere('state', 'pending'))
            ->whereBetween('payable_micro', $microRange)
            ->when($topUpIntentId !== null, fn ($q) => $q->where('id', '<>', $topUpIntentId))
            ->select('payment_snapshot');
        $topUps = DB::table('customer_usdt_top_ups as targets')
            ->leftJoin('customer_usdt_top_up_resolutions as resolutions', 'resolutions.top_up_id', '=', 'targets.id')
            ->where('targets.rail', 'bsc_shared')->where('targets.created_at', '<=', $at)
            ->where(fn ($q) => $q->where('targets.created_at', '>=', $recent)->orWhereNull('resolutions.top_up_id'))
            ->whereBetween('targets.payable_micro', $microRange)
            ->when($topUpIntentId !== null, fn ($q) => $q->where('targets.intent_id', '<>', $topUpIntentId))
            ->select('targets.payment_snapshot');

        $candidates = DB::query()->fromSub($intents->unionAll($topUps), 'candidates');
        $grammar = $candidates->getGrammar();

        return $candidates
            ->whereRaw('LOWER('.$grammar->wrap('payment_snapshot->receiver_address').') = ?', [$receiver])
            ->whereRaw('LOWER('.$grammar->wrap('payment_snapshot->token_contract').') = ?', [$contract])
            ->whereRaw('CAST('.$grammar->wrap('payment_snapshot->token_decimals').' AS varchar(3)) = ?', [(string) $decimals])
            ->exists();
    }
}
