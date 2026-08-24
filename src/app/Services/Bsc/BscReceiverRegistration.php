<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;

final readonly class BscReceiverRegistration
{
    public function __construct(
        public BscPaymentReceiver $receiver,
        public BscReceiverOwnershipChallenge $challenge,
    ) {}
}
