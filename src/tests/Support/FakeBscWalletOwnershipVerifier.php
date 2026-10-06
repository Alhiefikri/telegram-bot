<?php

namespace Tests\Support;

use App\Models\BscPaymentReceiver;
use App\Models\BscReceiverOwnershipChallenge;
use App\Services\Bsc\BscWalletOwnershipVerifierInterface;

/**
 * Ownership-proof double for tests. It accepts one literal test signature and
 * binds the verified message to the claimed address, so tests prove which
 * message was verified without holding any private key material.
 */
final class FakeBscWalletOwnershipVerifier implements BscWalletOwnershipVerifierInterface
{
    public const VALID_SIGNATURE = 'valid-ownership-signature';

    /** @var list<array{address: string, chain_id: int, message: string, wallet_type: string}> */
    public array $verifiedMessages = [];

    public function __construct(private bool $accept = true) {}

    public function reject(): void
    {
        $this->accept = false;
    }

    public function verify(
        BscPaymentReceiver $receiver,
        BscReceiverOwnershipChallenge $challenge,
        string $walletType,
        string $signature,
    ): bool {
        return $this->accept
            && $signature === self::VALID_SIGNATURE
            && strtolower((string) $receiver->address_normalized) === strtolower((string) $challenge->wallet_address);
    }

    public function verifyMessage(
        string $addressNormalized,
        int $chainId,
        string $message,
        string $walletType,
        string $signature,
    ): bool {
        $this->verifiedMessages[] = [
            'address' => $addressNormalized,
            'chain_id' => $chainId,
            'message' => $message,
            'wallet_type' => $walletType,
        ];

        return $this->accept
            && $signature === self::VALID_SIGNATURE
            && str_contains($message, $addressNormalized);
    }
}
