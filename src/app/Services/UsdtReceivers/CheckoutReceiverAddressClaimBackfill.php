<?php

namespace App\Services\UsdtReceivers;

use App\Enums\UsdtReceiverChannel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Additive backfill that gives every existing checkout receiver its permanent
 * `checkout` address claim. Checkout addresses are already globally unique, so
 * only a claim has to be created and linked; no amount, status, provider or
 * payment snapshot is touched, and the operation is idempotent.
 */
final class CheckoutReceiverAddressClaimBackfill
{
    /**
     * @return int number of checkout receivers linked to a claim
     */
    public function run(): int
    {
        if (! Schema::hasTable('bsc_payment_receivers') || ! Schema::hasTable('usdt_receiver_address_claims')) {
            return 0;
        }

        $claimedAt = now();
        $linked = 0;

        /** @var iterable<object{id:int|string,chain_id:int|string,address_normalized:string,address_claim_id:int|string|null}> $receivers */
        $receivers = DB::table('bsc_payment_receivers')
            ->select(['id', 'chain_id', 'address_normalized', 'address_claim_id'])
            ->orderBy('id')
            ->get();

        foreach ($receivers as $receiver) {
            if ($receiver->address_claim_id !== null) {
                continue;
            }

            $chainId = (int) $receiver->chain_id;
            $address = (string) $receiver->address_normalized;

            $claimId = DB::table('usdt_receiver_address_claims')
                ->where('chain_id', $chainId)
                ->where('address_normalized', $address)
                ->value('id');

            if ($claimId === null) {
                $claimId = DB::table('usdt_receiver_address_claims')->insertGetId([
                    'chain_id' => $chainId,
                    'address_normalized' => $address,
                    'channel' => UsdtReceiverChannel::Checkout->value,
                    'claimed_at' => $claimedAt,
                ], 'id');
            } else {
                $channel = DB::table('usdt_receiver_address_claims')->where('id', $claimId)->value('channel');
                if ($channel !== UsdtReceiverChannel::Checkout->value) {
                    throw new RuntimeException(sprintf(
                        'Checkout receiver [%s] references address [%s] already claimed by channel [%s]. Resolve the receiver ownership conflict before retrying the backfill.',
                        (string) $receiver->id,
                        $address,
                        (string) $channel,
                    ));
                }
            }

            DB::table('bsc_payment_receivers')
                ->where('id', $receiver->id)
                ->update(['address_claim_id' => (int) $claimId]);

            $linked++;
        }

        return $linked;
    }
}
