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
}
