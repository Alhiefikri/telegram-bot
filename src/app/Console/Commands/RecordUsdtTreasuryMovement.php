<?php

namespace App\Console\Commands;

use App\Enums\UsdtTreasuryMovementDirection;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtTreasury\UsdtTreasuryMovementInput;
use App\Services\UsdtTreasury\UsdtTreasuryMovements;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * Records one audited custody movement. This command is bookkeeping only:
 * the actual transfer is signed outside the application, and recording it
 * never credits a customer balance or moves custody funds.
 */
final class RecordUsdtTreasuryMovement extends Command
{
    protected $signature = 'usdt-balance:record-treasury-movement
        {--direction= : Movement direction: in or out.}
        {--amount= : Exact positive USDT decimal amount.}
        {--reference= : Unique external movement reference (for example the signed transaction hash).}
        {--reason= : Audit reason or category.}
        {--actor= : Operator identity recorded on the movement.}
        {--occurred-at= : Movement timestamp (any strtotime-compatible value, defaults to now).}
        {--json : Print the sanitized JSON result.}';

    protected $description = 'Record one audited USDT treasury movement (bookkeeping only).';

    public function handle(UsdtTreasuryMovements $movements): int
    {
        try {
            $input = $this->movementInput();
            $result = $movements->record($input);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('The USDT treasury movement could not be recorded.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'status' => $result->status->value,
                'movement_id' => $result->movementId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf('SUMMARY status=%s movement_id=%d', $result->status->value, $result->movementId));
        }

        return self::SUCCESS;
    }

    private function movementInput(): UsdtTreasuryMovementInput
    {
        $direction = UsdtTreasuryMovementDirection::tryFrom(trim((string) ($this->option('direction') ?? '')));
        if ($direction === null) {
            throw new InvalidArgumentException('The --direction option must be in or out.');
        }

        $occurredAt = trim((string) ($this->option('occurred-at') ?? ''));

        return new UsdtTreasuryMovementInput(
            direction: $direction,
            amount: UsdtMicroAmount::fromDecimalString($this->required('amount')),
            reference: $this->required('reference'),
            reason: $this->required('reason'),
            actor: $this->required('actor'),
            occurredAt: $occurredAt === '' ? CarbonImmutable::now() : CarbonImmutable::parse($occurredAt),
        );
    }

    private function required(string $option): string
    {
        $value = trim((string) ($this->option($option) ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("The --{$option} option is required.");
        }

        return $value;
    }
}
