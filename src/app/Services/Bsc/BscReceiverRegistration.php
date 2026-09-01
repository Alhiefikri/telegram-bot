<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;

final readonly class BscReceiverRegistration
{
    public function __construct(
        public BscPaymentReceiver $receiver,
        // New registrations are directly active; this remains nullable for
        // callers that still consume the legacy registration shape.
        public ?BscReceiverOwnershipChallenge $challenge = null,
    ) {}
}
