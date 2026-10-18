<?php

namespace App\Services\UsdtTopUps;

use App\Services\Bsc\BscChainFacts;
use App\Services\Bsc\BscFinalReceiptInspection;
use App\Services\Bsc\BscFinalReceiptInspector;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscTokenAmount;
use App\Services\Bsc\BscVerificationRpcClientInterface;

/**
 * Read-only inspection for the shared-address BSC rail. The shared address is
 * a fixed snapshot on the target; the Customer-submitted transaction hash is
 * only a lookup hint. Verification still requires one authoritative USDT
 * Transfer to the snapshot address, the exact payable amount, the invoice
 * window and finalized canonical facts.
 */
final class SharedBscReceiptInspector
{
    public function __construct(
        private readonly BscVerificationRpcClientInterface $rpc,
        private readonly BscFinalReceiptInspector $inspector,
        private readonly BscTokenAmount $amounts,
    ) {}

    /** @param array<string, mixed> $snapshot */
    public function inspect(string $transactionHash, array $snapshot, int $payableMicro, bool $allowLate = false): BscFinalReceiptInspection
    {
        $chainId = (int) ($snapshot['chain_id'] ?? 0);
        $contract = strtolower((string) ($snapshot['token_contract'] ?? ''));
        $receiver = strtolower((string) ($snapshot['receiver_address'] ?? ''));
        $decimals = (int) ($snapshot['token_decimals'] ?? 0);
        $startsAt = (int) ($snapshot['starts_at_epoch'] ?? 0);
        $expiresAt = (int) ($snapshot['expires_at_epoch'] ?? 0);
        $minimumConfirmations = (int) ($snapshot['minimum_confirmations'] ?? 0);

        if ($chainId !== 56 || $contract !== strtolower(BscReadiness::APPROVED_USDT_CONTRACT)
            || $decimals !== 18 || $receiver === '' || $startsAt < 1 || $expiresAt <= $startsAt
            || $minimumConfirmations < 1 || ($snapshot['requires_finalized_block'] ?? false) !== true) {
            return new BscFinalReceiptInspection('review', 'shared_snapshot_invalid');
        }

        try {
            if ($this->rpc->chainId() !== $chainId || $this->rpc->tokenDecimals($contract) !== $decimals) {
                return new BscFinalReceiptInspection('review', 'rpc_network_or_token_mismatch');
            }

            $receipt = $this->rpc->transactionReceipt($transactionHash);
            $number = $receipt === null ? null : BscChainFacts::quantity($receipt['blockNumber'] ?? null);
            if ($number === null) {
                return $receipt === null
                    ? new BscFinalReceiptInspection('pending', 'receipt_missing')
                    : new BscFinalReceiptInspection('review', 'receipt_identity_malformed');
            }

            $block = $this->rpc->blockByNumber($number);
            $latest = $this->rpc->blockByNumber('latest');
            $finalized = $this->rpc->blockByNumber('finalized');
            $finalFacts = BscChainFacts::block($finalized);
            if ($finalFacts !== null) {
                $canonicalFinal = BscChainFacts::block($this->rpc->blockByNumber($finalFacts['number']));
                if ($canonicalFinal === null) {
                    return new BscFinalReceiptInspection('pending', 'canonical_finality_unavailable');
                }
                if ($canonicalFinal !== $finalFacts) {
                    return new BscFinalReceiptInspection('review', 'finalized_block_not_canonical');
                }
            }

            return $this->inspector->inspect(
                $transactionHash,
                $receipt,
                $block,
                $latest,
                $finalized,
                $contract,
                $receiver,
                $this->amounts->expectedTokenUnits($payableMicro, $decimals),
                $decimals,
                $startsAt,
                $allowLate ? PHP_INT_MAX : $expiresAt,
                $minimumConfirmations,
            );
        } catch (BscRpcException) {
            return new BscFinalReceiptInspection('pending', 'rpc_unavailable');
        }
    }
}
