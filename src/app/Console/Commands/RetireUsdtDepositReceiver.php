<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesOperatorActor;
use App\Models\CustomerUsdtDepositReceiver;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use Throwable;

/**
 * Retires one Customer USDT deposit receiver so no new invoice is assigned to
 * it. The permanent Customer binding, address claim and history stay intact,
 * and the address keeps being monitored for late money.
 */
final class RetireUsdtDepositReceiver extends Command
{
    use ResolvesOperatorActor;

    protected $signature = 'usdt-receivers:retire
        {--receiver= : Exact USDT deposit receiver ID.}
        {--reason= : Audit reason for retiring the receiver.}
        {--actor= : Owner user ID or email recorded as the acting Owner.}
        {--json : Print the sanitized JSON result.}';

    protected $description = 'Retire one Customer-exclusive USDT deposit receiver from new invoice assignment.';

    public function handle(UsdtDepositReceiverRegistry $registry): int
    {
        try {
            $actor = $this->resolveOperatorActor();
            $receiverId = $this->required('receiver');
            if (! ctype_digit($receiverId)) {
                throw new InvalidArgumentException('--receiver harus berupa ID numerik exact.');
            }

            $receiver = CustomerUsdtDepositReceiver::query()->findOrFail((int) $receiverId);
            $retired = $registry->retire($receiver, $actor, $this->required('reason'));
        } catch (InvalidArgumentException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('USDT deposit receiver tidak dapat di-retire: '.$exception->getMessage());

            return self::FAILURE;
        }

        $payload = [
            'receiver_id' => (int) $retired->getKey(),
            'customer_id' => (int) $retired->customer_id,
            'masked_address' => $retired->maskedAddress(),
            'retired_at' => $retired->retired_at?->toIso8601String(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                'SUMMARY receiver_id=%d retired_at=%s',
                $payload['receiver_id'],
                (string) $payload['retired_at'],
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
