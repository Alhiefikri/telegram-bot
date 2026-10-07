<?php

namespace App\Services\Bsc;

use App\Services\UsdtBalance\UsdtMicroAmount;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Pure inspection, shared with the existing checkout token-log decoder. */
final class BscFinalReceiptInspector
{
    public const MAX_LOGS = 100;

    public function __construct(private readonly BscTokenTransferReader $reader) {}

    public function inspect(
        string $hash,
        ?array $receipt,
        ?array $block,
        ?array $latest,
        ?array $finalized,
        string $contract,
        string $receiver,
        string $expectedUnits,
        int $decimals,
        int $startsAt,
        int $expiresAt,
        int $minimumConfirmations,
    ): BscFinalReceiptInspection {
        if ($receipt === null) {
            return new BscFinalReceiptInspection('pending', 'receipt_missing');
        }
        if (($receipt['status'] ?? null) !== '0x1') {
            return new BscFinalReceiptInspection('review', 'receipt_failed_or_malformed');
        }
        $receiptNumber = BscChainFacts::quantity($receipt['blockNumber'] ?? null);
        $receiptHash = BscChainFacts::hash($receipt['blockHash'] ?? null);
        if ($receiptNumber === null || $receiptHash === null
            || BscChainFacts::hash($receipt['transactionHash'] ?? null) !== $hash) {
            return new BscFinalReceiptInspection('review', 'receipt_identity_malformed');
        }
        $logs = $receipt['logs'] ?? null;
        if (! is_array($logs) || ! array_is_list($logs) || count($logs) > self::MAX_LOGS) {
            return new BscFinalReceiptInspection('review', 'receipt_logs_malformed_or_oversized');
        }
        $transferLogs = [];
        $indices = [];
        foreach ($logs as $log) {
            if (! is_array($log) || ! is_array($log['topics'] ?? null)) {
                return new BscFinalReceiptInspection('review', 'log_malformed');
            }
            // Other contract events are irrelevant, but every Transfer must be
            // fully decoded: skipping a malformed second transfer is unsafe.
            if (($log['topics'][0] ?? null) !== BscRpcClient::TRANSFER_TOPIC) {
                continue;
            }
            $index = BscChainFacts::quantity($log['logIndex'] ?? null);
            if ($index === null || isset($indices[$index])
                || BscChainFacts::hash($log['transactionHash'] ?? null) !== $hash
                || BscChainFacts::hash($log['blockHash'] ?? null) !== $receiptHash
                || BscChainFacts::quantity($log['blockNumber'] ?? null) !== $receiptNumber
                || ($log['removed'] ?? null) !== false
                || ! $this->validTransferEncoding($log)) {
                return new BscFinalReceiptInspection('review', 'log_identity_or_encoding_malformed');
            }
            $indices[$index] = true;
            $transferLogs[] = $log;
        }
        $decoded = $this->reader->inspectTransferLogs($transferLogs, $contract, $receiver, $expectedUnits);
        if (count($decoded->transfers) !== count($transferLogs)) {
            return new BscFinalReceiptInspection('review', 'transfer_decode_incomplete');
        }
        // One physical transfer only; never sum logs or ignore another deposit
        // because only one of several happens to match the expected amount.
        if (count($decoded->transfers) !== 1) {
            return new BscFinalReceiptInspection('review', 'transfer_mismatch_or_ambiguous');
        }
        $evidence = $decoded->transfers[0];
        if ($evidence->tokenContract !== strtolower($contract) || $evidence->receiverAddress !== strtolower($receiver)) {
            return new BscFinalReceiptInspection('review', 'transfer_mismatch_or_ambiguous');
        }
        try {
            UsdtMicroAmount::fromRawTokenUnits($evidence->amountUnits, $decimals);
        } catch (InvalidArgumentException) {
            return new BscFinalReceiptInspection('review', 'amount_not_representable');
        }
        $canonical = BscChainFacts::block($block);
        $head = BscChainFacts::block($latest);
        $final = BscChainFacts::block($finalized);
        if ($canonical === null || $head === null || $final === null) {
            return new BscFinalReceiptInspection('pending', 'block_or_finality_unavailable');
        }
        if ($canonical['number'] !== $receiptNumber || $canonical['hash'] !== $receiptHash
            || $head['number'] < $receiptNumber) {
            return new BscFinalReceiptInspection('review', 'canonical_block_changed');
        }
        if ($canonical['timestamp'] < $startsAt || $canonical['timestamp'] > $expiresAt) {
            return new BscFinalReceiptInspection('review', 'outside_invoice_window');
        }
        if ($final['number'] >= $head['number'] || $final['number'] < $receiptNumber
            || $minimumConfirmations > $head['number'] - $receiptNumber + 1) {
            return new BscFinalReceiptInspection('pending', 'finality_not_reached');
        }
        if ($final['number'] === $receiptNumber && $final['hash'] !== $receiptHash) {
            return new BscFinalReceiptInspection('review', 'finalized_hash_changed');
        }

        // Authentic funds and invoice eligibility are separate facts. Preserve
        // fully validated actual money for treasury even when it cannot settle
        // the requested amount automatically.
        $matchesAmount = $evidence->amountUnits === $expectedUnits;

        return new BscFinalReceiptInspection($matchesAmount ? 'verified' : 'review',
            $matchesAmount ? 'canonical_final_transfer' : 'canonical_final_amount_mismatch',
            $evidence->withChainFacts(Carbon::createFromTimestampUTC($canonical['timestamp']), $head['number'] - $receiptNumber + 1));
    }

    public function validTransferEncoding(array $log): bool
    {
        $topics = $log['topics'] ?? null;

        return is_string($log['address'] ?? null)
            && preg_match('/\A0x[0-9a-fA-F]{40}\z/', $log['address']) === 1
            && is_array($topics) && array_is_list($topics) && count($topics) === 3
            && $topics[0] === BscRpcClient::TRANSFER_TOPIC
            && is_string($topics[1]) && preg_match('/\A0x0{24}[0-9a-fA-F]{40}\z/', $topics[1]) === 1
            && is_string($topics[2]) && preg_match('/\A0x0{24}[0-9a-fA-F]{40}\z/', $topics[2]) === 1
            && is_string($log['data'] ?? null) && preg_match('/\A0x[0-9a-fA-F]{64}\z/', $log['data']) === 1;
    }
}
