<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOperatorActor;
use App\Services\UsdtFunding\SharedTopUpReceiverRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * Registers or deactivates the single shared BSC address used by the
 * bsc_shared top-up rail. The address is public; the private key never enters
 * the application. A claimed address can never be reused for checkout or a
 * Customer-exclusive deposit binding.
 */
final class ConfigureUsdtSharedTopUpReceiver extends Command
{
    use ResolvesOperatorActor;

    protected $signature = 'usdt-topup:shared-receiver
        {--address= : Public BSC address dedicated to shared top-ups.}
        {--label=Top-up BSC : Admin label for the address.}
        {--actor= : Owner user ID or email recorded as the acting Owner.}
        {--deactivate : Deactivate the current shared top-up address without registering a new one.}
        {--json : Print the sanitized JSON result.}';

    protected $description = 'Set or deactivate the shared BSC address for Customer USDT top-ups.';

    public function handle(SharedTopUpReceiverRegistry $registry): int
    {
        try {
            $actor = $this->resolveOperatorActor();

            if ((bool) $this->option('deactivate')) {
                $changed = $registry->deactivate($actor);

                return $this->printResult(['deactivated' => $changed]);
            }

            $address = trim((string) ($this->option('address') ?? ''));
            if ($address === '') {
                throw new InvalidArgumentException('The --address option is required unless --deactivate is used.');
            }

            $receiver = $registry->activate($actor, $address, (string) $this->option('label'));

            return $this->printResult([
                'receiver_id' => (int) $receiver->getKey(),
                'label' => (string) $receiver->label,
                'masked_address' => $receiver->maskedAddress(),
                'chain_id' => (int) $receiver->chain_id,
                'token_contract' => (string) $receiver->token_contract,
                'token_decimals' => (int) $receiver->token_decimals,
                'is_active' => (bool) $receiver->is_active,
            ]);
        } catch (InvalidArgumentException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Shared top-up receiver could not be changed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $payload */
    private function printResult(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('SUMMARY '.json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }
}
