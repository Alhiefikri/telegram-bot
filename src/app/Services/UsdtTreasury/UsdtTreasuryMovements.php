<?php

namespace App\Services\UsdtTreasury;

use App\Models\UsdtTreasuryMovement;
use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Append-only audit of custody movements outside customer funding receipts.
 * One (direction, reference) economic identity produces exactly one row: a
 * replay returns the existing movement, and a changed amount is a conflict.
 * This service never touches a customer balance, ledger entry or accounting
 * event, and it never performs an on-chain or provider action — the signed
 * transfer itself happens outside the application.
 */
final class UsdtTreasuryMovements
{
    public function record(UsdtTreasuryMovementInput $input): UsdtTreasuryMovementResult
    {
        $claimKey = $this->claimKey($input);

        try {
            return DB::transaction(function () use ($input, $claimKey): UsdtTreasuryMovementResult {
                $existing = UsdtTreasuryMovement::query()->where('claim_key', $claimKey)->first();
                if ($existing !== null) {
                    return $this->resolveExisting($existing, $input);
                }

                $movement = UsdtTreasuryMovement::query()->create([
                    'claim_key' => $claimKey,
                    'currency' => UsdtMicroAmount::CURRENCY,
                    'direction' => $input->direction,
                    'amount_micro' => $input->amount->units(),
                    'reference' => $input->reference,
                    'reason' => $input->reason,
                    'actor' => $input->actor,
                    'occurred_at' => $input->occurredAt,
                    'created_at' => now(),
                ]);

                return new UsdtTreasuryMovementResult(UsdtTreasuryMovementStatus::Applied, (int) $movement->id);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = UsdtTreasuryMovement::query()->where('claim_key', $claimKey)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->resolveExisting($existing, $input);
        }
    }

    public function totals(): UsdtTreasuryMovementTotals
    {
        $row = DB::table('usdt_treasury_movements')
            ->where('currency', UsdtMicroAmount::CURRENCY)
            ->selectRaw(
                "COUNT(*) as movement_count, COALESCE(SUM(CASE WHEN direction = 'in' THEN amount_micro ELSE 0 END), 0) as in_micro, COALESCE(SUM(CASE WHEN direction = 'out' THEN amount_micro ELSE 0 END), 0) as out_micro",
            )
            ->first();

        return UsdtTreasuryMovementTotals::fromDatabase(
            (int) ($row->movement_count ?? 0),
            $row->in_micro ?? null,
            $row->out_micro ?? null,
        );
    }

    private function resolveExisting(UsdtTreasuryMovement $existing, UsdtTreasuryMovementInput $input): UsdtTreasuryMovementResult
    {
        if ($existing->direction !== $input->direction || (int) $existing->amount_micro !== $input->amount->units()) {
            throw new InvalidArgumentException('The USDT treasury movement reference was already recorded with a different direction or amount.');
        }

        return new UsdtTreasuryMovementResult(UsdtTreasuryMovementStatus::Replayed, (int) $existing->id);
    }

    private function claimKey(UsdtTreasuryMovementInput $input): string
    {
        return hash('sha256', implode('|', [
            UsdtMicroAmount::CURRENCY,
            $input->direction->value,
            $input->reference,
        ]));
    }
}
