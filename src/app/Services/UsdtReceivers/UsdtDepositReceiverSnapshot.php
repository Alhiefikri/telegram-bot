<?php

namespace App\Services\UsdtReceivers;

use App\Models\CustomerUsdtDepositReceiver;
use InvalidArgumentException;

/**
 * Immutable snapshot of one assignable Customer USDT deposit receiver: the
 * exclusive receiver identity, its permanent Customer binding and the
 * chain/token metadata that a USDT top-up must freeze before any invoice
 * exists. Retirement later never rewrites an existing snapshot.
 */
final readonly class UsdtDepositReceiverSnapshot
{
    public function __construct(
        public int $receiverId,
        public int $customerId,
        public string $address,
        public int $chainId,
        public string $tokenContract,
        public int $tokenDecimals,
        public string $custodyNamespace,
    ) {}

    public static function fromReceiver(CustomerUsdtDepositReceiver $receiver): self
    {
        if (! $receiver->isReadyForAssignment()) {
            throw new InvalidArgumentException('Only non-retired verified USDT deposit receivers can be snapshotted.');
        }

        $address = CustomerUsdtDepositReceiver::normalizeAddress((string) $receiver->address_normalized);
        $tokenContract = CustomerUsdtDepositReceiver::normalizeAddress((string) $receiver->token_contract);
        $chainId = (int) $receiver->chain_id;
        $tokenDecimals = (int) $receiver->token_decimals;

        if ($chainId !== UsdtDepositReceiverRegistry::SUPPORTED_CHAIN_ID) {
            throw new InvalidArgumentException('A USDT deposit receiver snapshot must use the approved BSC chain.');
        }

        if ($tokenDecimals < 6) {
            throw new InvalidArgumentException('USDT deposit receiver token decimals must support integer micro-USDT conversion.');
        }

        return new self(
            receiverId: (int) $receiver->getKey(),
            customerId: (int) $receiver->customer_id,
            address: $address,
            chainId: $chainId,
            tokenContract: $tokenContract,
            tokenDecimals: $tokenDecimals,
            custodyNamespace: (string) $receiver->custody_namespace,
        );
    }
}
