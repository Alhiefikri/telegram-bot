<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use InvalidArgumentException;

final readonly class BscPaymentReceiverSnapshot
{
    public function __construct(
        public int $receiverId,
        public string $address,
        public int $chainId,
        public string $tokenContract,
        public int $tokenDecimals,
    ) {}

    public static function fromReceiver(BscPaymentReceiver $receiver): self
    {
        if (! $receiver->isReadyForAssignment()) {
            throw new InvalidArgumentException('Only active verified BSC receivers can be snapshotted.');
        }

        $address = BscPaymentReceiver::normalizeAddress((string) $receiver->address_normalized);
        $tokenContract = BscPaymentReceiver::normalizeAddress((string) $receiver->token_contract);
        $chainId = (int) $receiver->chain_id;
        $tokenDecimals = (int) $receiver->token_decimals;

        if ($chainId !== 56) {
            throw new InvalidArgumentException('BSC receiver snapshot must use BNB Smart Chain Mainnet.');
        }

        if ($tokenDecimals < 6) {
            throw new InvalidArgumentException('BSC token decimals must support integer micro-USDT conversion.');
        }

        return new self(
            receiverId: (int) $receiver->getKey(),
            address: $address,
            chainId: $chainId,
            tokenContract: $tokenContract,
            tokenDecimals: $tokenDecimals,
        );
    }
}
