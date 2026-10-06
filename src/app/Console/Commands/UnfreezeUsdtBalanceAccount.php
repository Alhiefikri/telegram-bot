<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Operations\UsdtBalanceAccountUnfreeze;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Deliberate operator unfreeze for one Customer USDT Balance account (#558).
 * The account is re-inspected under its row lock: a remaining drift keeps the
 * freeze, and a reorg incident keeps the freeze until the loss is resolved by
 * the authorized recovery process. No ledger entry, projection balance or
 * receipt is written.
 */
final class UnfreezeUsdtBalanceAccount extends Command
{
    protected $signature = 'usdt-balance:unfreeze
        {--customer= : Exact numeric Customer id}
        {--reason= : Operator reason recorded on the audit log}
        {--actor= : Operator identity recorded on the audit log}
        {--dry-run : Prove eligibility without clearing the freeze}
        {--json : Print the sanitized JSON result}';

    protected $description = 'Clear a drift freeze on a Customer USDT Balance account only when a fresh re-check proves no drift remains.';

    public function handle(UsdtBalanceAccountUnfreeze $unfreeze): int
    {
        try {
            $customerId = trim((string) ($this->option('customer') ?? ''));
            if (preg_match('/\A[1-9][0-9]*\z/', $customerId) !== 1) {
                throw new InvalidArgumentException('The --customer option must be an exact positive numeric Customer id.');
            }

            $customer = Customer::query()->whereKey((int) $customerId)->first();
            if ($customer === null) {
                throw new InvalidArgumentException('Customer not found.');
            }

            $result = $unfreeze->unfreeze(
                $customer,
                (string) ($this->option('reason') ?? ''),
                (string) ($this->option('actor') ?? ''),
                (bool) $this->option('dry-run'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = $result->toArray();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'UNFREEZE customer_id=%d account_id=%s status=%s frozen_reason=%s issues=%s',
                $result->customerId,
                $result->accountId ?? 0,
                $result->status->value,
                $result->frozenReason ?? 'none',
                $result->issues === [] ? 'none' : implode(',', $result->issues),
            ));
        }

        if (! $result->status->isSuccess()) {
            if ($result->status->value === 'drift_remains') {
                $this->error('Reconciliation still proves drift on this account; the freeze stays.');
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
