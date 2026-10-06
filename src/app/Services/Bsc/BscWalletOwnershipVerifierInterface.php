<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;

interface BscWalletOwnershipVerifierInterface
{
    public function verify(
        BscPaymentReceiver $receiver,
        BscReceiverOwnershipChallenge $challenge,
        string $walletType,
        string $signature,
    ): bool;

    /**
     * Verifies a domain-separated ownership message against a plain public
     * address and chain, so every receiver registry (checkout and
     * Customer-exclusive USDT deposit) shares one ownership-proof contract.
     */
    public function verifyMessage(
        string $addressNormalized,
        int $chainId,
        string $message,
        string $walletType,
        string $signature,
    ): bool;
}
