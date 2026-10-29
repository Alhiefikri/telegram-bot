<?php

namespace App\Services\UsdtReceivers;

use App\Enums\UsdtReceiverChannel;
use App\Exceptions\UsdtReceiverAddressConflict;
use App\Models\BscPaymentReceiver;
use App\Models\UsdtReceiverAddressClaim;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns the permanent channel claim of one normalized (chain_id, address).
 * Shared top-ups may reference an existing verified checkout claim without
 * changing its channel; Customer deposit claims remain exclusive.
 *
 * A checkout claim may be re-used for checkout again only after its receiver
 * row was deleted (the address then never served a payment). A Customer
 * deposit claim is never re-used: once an address ever entered the exclusive
 * deposit channel it stays owned by that binding, retired or not. Conflicting
 * inserts are recovered through a savepoint, so a caller transaction is never
 * left aborted.
 */
final class UsdtReceiverAddressClaims
{
    public function find(int $chainId, string $address): ?UsdtReceiverAddressClaim
    {
        return UsdtReceiverAddressClaim::query()
            ->forAddress($chainId, BscPaymentReceiver::normalizeAddress($address))
            ->first();
    }

    /**
     * @throws UsdtReceiverAddressConflict when the address is owned by another channel, another
     *                                     live checkout receiver or any previous deposit binding.
     */
    public function claim(int $chainId, string $address, UsdtReceiverChannel $channel): UsdtReceiverAddressClaim
    {
        $normalized = BscPaymentReceiver::normalizeAddress($address);

        $existing = UsdtReceiverAddressClaim::query()->forAddress($chainId, $normalized)->first();
        if ($existing !== null) {
            return $this->reuseOrConflict($existing, $channel);
        }

        try {
            return DB::transaction(fn (): UsdtReceiverAddressClaim => UsdtReceiverAddressClaim::create([
                'chain_id' => $chainId,
                'address_normalized' => $normalized,
                'channel' => $channel,
                'claimed_at' => Carbon::now(),
            ]), 1);
        } catch (QueryException $exception) {
            if (! $this->isAddressUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = UsdtReceiverAddressClaim::query()->forAddress($chainId, $normalized)->first();
            if ($existing === null) {
                throw $exception;
            }

            return $this->reuseOrConflict($existing, $channel);
        }
    }

    private function reuseOrConflict(UsdtReceiverAddressClaim $claim, UsdtReceiverChannel $channel): UsdtReceiverAddressClaim
    {
        if ($claim->channel !== $channel) {
            throw new UsdtReceiverAddressConflict(sprintf(
                'Address ini sudah diklaim kanal %s dan tidak dapat dipakai kanal %s.',
                $claim->channel->value,
                $channel->value,
            ));
        }

        if ($channel === UsdtReceiverChannel::CustomerDeposit) {
            throw new UsdtReceiverAddressConflict(
                'Address ini sudah pernah diklaim sebagai USDT deposit receiver dan tidak dapat dipakai ulang.',
            );
        }

        if ($channel === UsdtReceiverChannel::SharedTopUp) {
            throw new UsdtReceiverAddressConflict(
                'Address ini sudah pernah diklaim sebagai shared top-up receiver dan tidak dapat dipakai ulang.',
            );
        }

        if ($claim->checkoutReceiver()->exists()) {
            throw new UsdtReceiverAddressConflict('BSC Receiving Address sudah terdaftar.');
        }

        return $claim;
    }

    private function isAddressUniqueViolation(QueryException $exception): bool
    {
        if (! in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true)) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'usdt_receiver_claims_address_unique')
            || (str_contains($message, 'usdt_receiver_address_claims')
                && str_contains($message, 'address_normalized'));
    }
}
