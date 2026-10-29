<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOperatorActor;
use App\Models\Customer;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtTopUps\UsdtTransferRecovery;
use Illuminate\Console\Command;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Throwable;

final class RecoverUsdtTransfer extends Command
{
    use ResolvesOperatorActor;

    protected $signature = 'usdt-funding:recover-transfer
        {--actor= : Owner user ID or email}
        {--telegram-id= : Exact registered Customer Telegram ID}
        {--receiver= : Verified checkout receiver ID}
        {--amount= : Exact USDT amount to verify and credit}
        {--txid= : Finalized BSC transaction hash}
        {--reason= : Owner attribution and recovery reason}';

    protected $description = 'Recover one verified BSC transfer without an invoice, using a hidden per-action Owner password prompt.';

    public function handle(UsdtTransferRecovery $recovery, CustomerUsdtBalanceLedger $ledger): int
    {
        if (! $this->input->isInteractive() || ! stream_isatty(STDIN)) {
            $this->error('Run interactively in a trusted terminal. Password arguments, pipes and environment variables are not supported.');

            return self::FAILURE;
        }
        $session = new Store('usdt-recovery-cli', new ArraySessionHandler(10));
        $session->start();
        request()->setLaravelSession($session);
        $password = null;
        try {
            $owner = $this->resolveOperatorActor();
            Auth::setUser($owner);
            $preview = $recovery->request($owner, (string) $this->option('telegram-id'), (int) $this->option('receiver'),
                (string) $this->option('amount'), (string) $this->option('txid'), (string) $this->option('reason'));
            $customer = Customer::query()->findOrFail($preview->customer_id);
            $this->line('Customer Telegram: '.$customer->telegram_id.' (@'.$customer->username.')');
            $this->line('Verified credit: '.UsdtMicroAmount::formatUnits($preview->amount_micro).' USD');
            $this->line('Receiver: '.$preview->evidence['receiver_address']);
            $this->line('TxID: '.$preview->transaction_hash);
            $this->line('Reason: '.$preview->reason);
            $this->warn('This assigns the transfer to this Customer. It does not prove ownership of the sending wallet.');
            $password = $this->secret('Owner password (hidden; authorizes this credit only)', false);
            if (! is_string($password) || $password === '') {
                throw new \DomainException('No password supplied; no credit posted.');
            }
            $result = $recovery->confirm($owner, $preview->id, $password);
            $password = null;
            $snapshot = $ledger->snapshot($customer);
            $this->line(json_encode(['recovery_id' => $result->id, 'entry_id' => $result->entry_id,
                'receipt_id' => $result->receipt_id, 'credited_micro' => $preview->amount_micro,
                'balance_micro' => $snapshot->isAvailable() ? $snapshot->balanceMicro() : null,
                'balance_available' => $snapshot->isAvailable()], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $password = null;
            Auth::forgetUser();
            $session->invalidate();
        }
    }
}
