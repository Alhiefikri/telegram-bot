<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOperatorActor;
use App\Exceptions\UsdtDepositReceiverAlreadyActive;
use App\Exceptions\UsdtReceiverAddressConflict;
use App\Models\Customer;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistration;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * Registers one Customer-exclusive USDT deposit receiver from the operator
 * console. The Customer must already exist (selected by exact ID or exact
 * numeric Telegram ID), the address is claimed permanently in the global
 * receiver registry, and the acting Owner is recorded in the audit trail.
 * Only public addresses are entered; no key or seed material is created.
 */
final class RegisterUsdtDepositReceiver extends Command
{
    use ResolvesOperatorActor;

    protected $signature = 'usdt-receivers:register
        {--customer-id= : Exact registered Customer ID.}
        {--telegram-id= : Exact registered numeric Telegram ID (alternative to --customer-id).}
        {--address= : Public BSC address to bind exclusively to the Customer.}
        {--actor= : Owner user ID or email recorded as the mapping actor.}
        {--manual-verified : Explicitly acknowledge the high-risk Owner custody attestation instead of a signed ownership challenge.}
        {--json : Print the sanitized JSON result.}';

    protected $description = 'Register one Customer-exclusive USDT deposit receiver (public address only, no key material).';

    public function handle(UsdtDepositReceiverRegistry $registry): int
    {
        try {
            $actor = $this->resolveOperatorActor();
            $customer = $this->customer();
            $address = $this->required('address');

            $registration = $registry->register($customer, $address, $actor);
            if ((bool) $this->option('manual-verified')) {
                $registration = new UsdtDepositReceiverRegistration(
                    $registry->manualVerify($registration->receiver, true, $actor),
                );
            }
        } catch (InvalidArgumentException|ModelNotFoundException|UsdtReceiverAddressConflict|UsdtDepositReceiverAlreadyActive $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('USDT deposit receiver tidak dapat didaftarkan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $receiver = $registration->receiver->fresh();
        $payload = [
            'receiver_id' => (int) $receiver->getKey(),
            'customer_id' => (int) $receiver->customer_id,
            'masked_address' => $receiver->maskedAddress(),
            'verification_status' => $receiver->verification_status?->value,
            'challenge_id' => $registration->challenge === null ? null : (string) $registration->challenge->getKey(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'SUMMARY receiver_id=%d customer_id=%d status=%s',
                $payload['receiver_id'],
                $payload['customer_id'],
                (string) $payload['verification_status'],
            ));

            if ($registration->challenge !== null) {
                $this->newLine();
                $this->line('Tanda tangani pesan ownership berikut lalu jalankan usdt-receivers:verify:');
                $this->newLine();
                $this->line((string) $registration->challenge->message);
            }
        }

        return self::SUCCESS;
    }

    private function customer(): Customer
    {
        $customerId = trim((string) ($this->option('customer-id') ?? ''));
        $telegramId = trim((string) ($this->option('telegram-id') ?? ''));

        if ($customerId !== '' && $telegramId !== '') {
            throw new InvalidArgumentException('Gunakan salah satu dari --customer-id atau --telegram-id.');
        }

        if ($customerId !== '') {
            if (! ctype_digit($customerId)) {
                throw new InvalidArgumentException('--customer-id harus berupa ID numerik exact.');
            }

            return Customer::query()->findOrFail((int) $customerId);
        }

        if ($telegramId !== '') {
            if (! ctype_digit($telegramId)) {
                throw new InvalidArgumentException('--telegram-id harus berupa Telegram ID numerik exact.');
            }

            return Customer::query()->where('telegram_id', (int) $telegramId)->firstOrFail();
        }

        throw new InvalidArgumentException('Pilih Customer dengan --customer-id atau --telegram-id.');
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
