<?php

namespace App\Services\UsdtFunding;

use App\Enums\UsdtReceiverChannel;
use App\Models\BscPaymentReceiver;
use App\Models\UsdtSharedTopUpReceiver;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Bsc\BscReadiness;
use App\Services\UsdtReceivers\UsdtReceiverAddressClaims;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owner-only lifecycle for the one active shared BSC top-up address. The
 * address is permanently claimed in the global receiver registry. An existing
 * verified checkout claim may also back this rail; Customer deposits stay exclusive.
 * Rotation deactivates the current row; history and the claim stay immutable.
 */
final class SharedTopUpReceiverRegistry
{
    public function __construct(
        private readonly UsdtReceiverAddressClaims $claims,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function active(): ?UsdtSharedTopUpReceiver
    {
        return UsdtSharedTopUpReceiver::query()->active()->orderByDesc('id')->first();
    }

    public function activate(User $actor, string $address, string $label = 'Top-up BSC'): UsdtSharedTopUpReceiver
    {
        $this->assertOwner($actor);
        $address = UsdtSharedTopUpReceiver::normalizeAddress($address);
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            throw new InvalidArgumentException('Shared top-up receiver label is required and must be at most 120 characters.');
        }

        $chainId = (int) config('services.bsc.chain_id', 56);
        $contract = strtolower((string) config('services.bsc.usdt_contract'));
        $decimals = (int) config('services.bsc.expected_token_decimals', 18);
        if ($chainId !== 56 || $contract !== strtolower(BscReadiness::APPROVED_USDT_CONTRACT) || $decimals !== 18) {
            throw new InvalidArgumentException('BSC configuration must use chain 56, the approved USDT contract and 18 decimals.');
        }

        return DB::transaction(function () use ($actor, $address, $label, $chainId, $contract, $decimals): UsdtSharedTopUpReceiver {
            $current = UsdtSharedTopUpReceiver::query()->active()->lockForUpdate()->get();
            foreach ($current as $receiver) {
                $receiver->forceFill(['is_active' => false])->save();
                $this->auditLogger->log(
                    'usdt_shared_top_up_receiver.deactivated',
                    $receiver,
                    ['is_active' => true],
                    $this->safeValues($receiver) + ['reason' => 'replaced_by_new_shared_top_up_address'],
                    $actor,
                );
            }

            $claim = $this->claims->find($chainId, $address);
            if ($claim?->channel === UsdtReceiverChannel::Checkout) {
                $checkout = BscPaymentReceiver::query()->where('address_claim_id', $claim->getKey())
                    ->lockForUpdate()->first();
                if ($checkout === null || ! $checkout->is_active || ! $checkout->isVerified()
                    || $checkout->chain_id !== $chainId || $checkout->token_contract !== $contract
                    || $checkout->token_decimals !== $decimals) {
                    throw new InvalidArgumentException('Shared top-ups require an active, verified checkout receiver for the approved BSC USDT token.');
                }
            } else {
                $claim = $this->claims->claim($chainId, $address, UsdtReceiverChannel::SharedTopUp);
            }

            $receiver = UsdtSharedTopUpReceiver::query()->create([
                'address_claim_id' => $claim->getKey(),
                'label' => $label,
                'chain_id' => $chainId,
                'address' => $address,
                'address_normalized' => $address,
                'token_contract' => $contract,
                'token_decimals' => $decimals,
                'is_active' => true,
                'created_by' => $actor->getKey(),
            ]);

            $this->auditLogger->log(
                'usdt_shared_top_up_receiver.activated',
                $receiver,
                null,
                $this->safeValues($receiver) + ['reason' => 'owner_registered_shared_top_up_address'],
                $actor,
            );

            return $receiver;
        }, 5);
    }

    public function deactivate(User $actor): bool
    {
        $this->assertOwner($actor);

        return DB::transaction(function () use ($actor): bool {
            $current = UsdtSharedTopUpReceiver::query()->active()->lockForUpdate()->get();
            if ($current->isEmpty()) {
                return false;
            }

            foreach ($current as $receiver) {
                $receiver->forceFill(['is_active' => false])->save();
                $this->auditLogger->log(
                    'usdt_shared_top_up_receiver.deactivated',
                    $receiver,
                    ['is_active' => true],
                    $this->safeValues($receiver) + ['reason' => 'owner_deactivated_shared_top_up_address'],
                    $actor,
                );
            }

            return true;
        }, 5);
    }

    private function assertOwner(User $actor): void
    {
        if (! $actor->role?->canManageUsdtReceivers()) {
            throw new AuthorizationException('Only an Owner can manage the shared top-up address.');
        }
    }

    /** @return array<string, mixed> */
    private function safeValues(UsdtSharedTopUpReceiver $receiver): array
    {
        return [
            'receiver_id' => (int) $receiver->getKey(),
            'label' => (string) $receiver->label,
            'masked_address' => $receiver->maskedAddress(),
            'address_fingerprint' => hash('sha256', (string) $receiver->address_normalized),
            'chain_id' => (int) $receiver->chain_id,
            'token_contract' => (string) $receiver->token_contract,
            'token_decimals' => (int) $receiver->token_decimals,
            'is_active' => (bool) $receiver->is_active,
        ];
    }
}
