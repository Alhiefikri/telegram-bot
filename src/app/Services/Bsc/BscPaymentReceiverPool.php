<?php

namespace App\Services\Bsc;

use App\Exceptions\InvalidCheckout;
use App\Models\BscPaymentReceiver;
use Illuminate\Database\Eloquent\Collection;

final class BscPaymentReceiverPool
{
    public function selectSnapshot(): BscPaymentReceiverSnapshot
    {
        return BscPaymentReceiverSnapshot::fromReceiver($this->selectLockedReceiver());
    }

    public function select(): BscPaymentReceiver
    {
        return $this->selectLockedReceiver();
    }

    private function selectLockedReceiver(): BscPaymentReceiver
    {
        [$chainId, $tokenContract, $tokenDecimals] = $this->configuredToken();

        /** @var Collection<int, BscPaymentReceiver> $receivers */
        $receivers = BscPaymentReceiver::query()
            ->activeVerified()
            ->where('chain_id', $chainId)
            ->where('token_contract', $tokenContract)
            ->where('token_decimals', $tokenDecimals)
            ->orderByRaw('CASE WHEN last_assigned_at IS NULL THEN 0 ELSE 1 END ASC')
            ->orderBy('last_assigned_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $receiver = $receivers->first();
        if ($receiver === null) {
            throw new InvalidCheckout('BSC payment rail has no active verified receiving address.');
        }

        $receiver->forceFill(['last_assigned_at' => now()])->save();

        return $receiver;
    }

    /** @return array{0:int,1:string,2:int} */
    private function configuredToken(): array
    {
        $chainId = (int) config('services.bsc.chain_id', 56);
        if ($chainId !== 56) {
            throw new InvalidCheckout('BSC payment rail requires BNB Smart Chain Mainnet.');
        }

        try {
            $tokenContract = BscPaymentReceiver::normalizeAddress((string) config('services.bsc.usdt_contract'));
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidCheckout('BSC payment rail has no valid approved token contract.', previous: $exception);
        }
        if ($tokenContract !== BscReadiness::APPROVED_USDT_CONTRACT) {
            throw new InvalidCheckout('BSC payment rail requires the approved BNB Smart Chain USDT contract.');
        }

        $tokenDecimals = (int) config('services.bsc.expected_token_decimals', 18);
        if ($tokenDecimals < 6) {
            throw new InvalidCheckout('BSC token decimals cannot represent integer micro-USDT.');
        }

        return [$chainId, $tokenContract, $tokenDecimals];
    }
}
