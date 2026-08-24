<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;

final class BscWalletOwnershipVerifier implements BscWalletOwnershipVerifierInterface
{
    public function __construct(
        private readonly BscEoaSignatureVerifier $eoa,
        private readonly BscRpcClientInterface $rpc,
    ) {}

    public function verify(
        BscPaymentReceiver $receiver,
        BscReceiverOwnershipChallenge $challenge,
        string $walletType,
        string $signature,
    ): bool {
        if (strtolower(trim((string) $challenge->wallet_address)) !== strtolower((string) $receiver->address_normalized)
            || (int) $challenge->chain_id !== (int) $receiver->chain_id
            || ! $challenge->isUsable()) {
            return false;
        }

        return match (strtolower(trim($walletType))) {
            'eoa' => $this->eoa->matchesAddress($challenge->message, $signature, $receiver->address_normalized),
            'erc1271', 'smart_contract' => $this->rpc->chainId() === (int) $receiver->chain_id
                && $this->rpc->isValidErc1271Signature(
                    $receiver->address_normalized,
                    BscEip191::messageHashHex($challenge->message),
                    $signature,
                ),
            default => false,
        };
    }
}
