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

        return $this->verifyMessage(
            (string) $receiver->address_normalized,
            (int) $receiver->chain_id,
            (string) $challenge->message,
            $walletType,
            $signature,
        );
    }

    public function verifyMessage(
        string $addressNormalized,
        int $chainId,
        string $message,
        string $walletType,
        string $signature,
    ): bool {
        return match (strtolower(trim($walletType))) {
            'eoa' => $this->eoa->matchesAddress($message, $signature, $addressNormalized),
            'erc1271', 'smart_contract' => $this->rpc->chainId() === $chainId
                && $this->rpc->isValidErc1271Signature(
                    $addressNormalized,
                    BscEip191::messageHashHex($message),
                    $signature,
                ),
            default => false,
        };
    }
}
