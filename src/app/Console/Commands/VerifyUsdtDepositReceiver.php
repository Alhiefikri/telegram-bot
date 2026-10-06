<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOperatorActor;
use App\Exceptions\UsdtDepositReceiverVerificationFailed;
use App\Models\CustomerUsdtDepositReceiver;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * Completes the ownership proof of one pending Customer USDT deposit receiver
 * with the signature produced for its challenge message.
 */
final class VerifyUsdtDepositReceiver extends Command
{
    use ResolvesOperatorActor;

    protected $signature = 'usdt-receivers:verify
        {--receiver= : Exact USDT deposit receiver ID.}
        {--challenge= : Challenge ID issued during registration.}
        {--wallet-type= : eoa, erc1271, or smart_contract.}
        {--signature= : Ownership signature over the challenge message.}
        {--actor= : Owner user ID or email recorded as the verification actor.}
        {--json : Print the sanitized JSON result.}';

    protected $description = 'Verify ownership of a pending Customer-exclusive USDT deposit receiver.';

    public function handle(UsdtDepositReceiverRegistry $registry): int
    {
        try {
            $actor = $this->resolveOperatorActor();
            $receiverId = $this->required('receiver');
            if (! ctype_digit($receiverId)) {
                throw new InvalidArgumentException('--receiver harus berupa ID numerik exact.');
            }

            $receiver = CustomerUsdtDepositReceiver::query()->findOrFail((int) $receiverId);
            $verified = $registry->verify(
                $receiver,
                $this->required('challenge'),
                $this->required('wallet-type'),
                $this->required('signature'),
                $actor,
            );
        } catch (InvalidArgumentException|ModelNotFoundException|UsdtDepositReceiverVerificationFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('USDT deposit receiver tidak dapat diverifikasi: '.$exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'receiver_id' => (int) $verified->getKey(),
            'customer_id' => (int) $verified->customer_id,
            'masked_address' => $verified->maskedAddress(),
            'verification_status' => $verified->verification_status?->value,
            'verification_method' => $verified->verification_method,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'SUMMARY receiver_id=%d status=%s method=%s',
                $payload['receiver_id'],
                (string) $payload['verification_status'],
                (string) $payload['verification_method'],
            ));
        }

        return self::SUCCESS;
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
