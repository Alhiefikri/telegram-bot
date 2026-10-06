<?php

namespace App\Services\UsdtReceivers;

use App\Models\CustomerUsdtDepositReceiver;
use App\Models\UsdtDepositReceiverOwnershipChallenge;

/**
 * Result of registering one pending Customer USDT deposit receiver. The
 * pending receiver is not assignable until an ownership proof is recorded.
 */
final readonly class UsdtDepositReceiverRegistration
{
    public function __construct(
        public CustomerUsdtDepositReceiver $receiver,
        public ?UsdtDepositReceiverOwnershipChallenge $challenge = null,
    ) {}
}
