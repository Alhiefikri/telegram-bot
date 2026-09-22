<?php

namespace App\Services\Bsc;

use Brick\Math\BigInteger;

final class BscTokenTransferReader
{
    /**
     * Decode only the public, bounded fields needed by the verifier. The raw
     * receipt is never returned or persisted by this class.
     */
    public function inspectReceipt(
        array $receipt,
        string $expectedContract,
        string $expectedReceiver,
        string $expectedAmountUnits,
        string $submittedHash,
    ): BscTransferInspection {
        $receiptState = $this->receiptState($receipt['status'] ?? null);
        if ($receiptState !== 'success') {
            return new BscTransferInspection($receiptState);
        }

        $blockNumber = $this->quantity($receipt['blockNumber'] ?? null);
        if ($blockNumber === null) {
            return new BscTransferInspection('invalid');
        }

        $transactionHash = BscTransactionHash::normalize((string) ($receipt['transactionHash'] ?? $submittedHash));
        if ($transactionHash === null || ! hash_equals($submittedHash, $transactionHash)) {
            return new BscTransferInspection('invalid');
        }

        $expectedContract = strtolower(trim($expectedContract));
        $expectedReceiver = strtolower(trim($expectedReceiver));
        $transfers = [];

        foreach (is_array($receipt['logs'] ?? null) ? $receipt['logs'] : [] as $log) {
            if (! is_array($log) || (bool) ($log['removed'] ?? false)) {
                continue;
            }

            $evidence = $this->decodeTransfer($log, $transactionHash, $blockNumber);
            if ($evidence !== null) {
                $transfers[] = $evidence;
            }
        }

        $exactTransfers = array_values(array_filter(
            $transfers,
            static fn (BscTransferEvidence $transfer): bool => hash_equals($expectedContract, $transfer->tokenContract)
                && hash_equals($expectedReceiver, $transfer->receiverAddress)
                && hash_equals($expectedAmountUnits, $transfer->amountUnits),
        ));

        return new BscTransferInspection('success', $transfers, $exactTransfers);
    }
    /**
     * Decode transfer logs returned by eth_getLogs. Receipt verification still
     * happens after a candidate hash is discovered; these logs only identify
     * which transaction should be checked.
     *
     * @param list<array<string, mixed>> $logs
     */
    public function inspectTransferLogs(
        array $logs,
        string $expectedContract,
        string $expectedReceiver,
        string $expectedAmountUnits,
    ): BscTransferInspection {
        $expectedContract = strtolower(trim($expectedContract));
        $expectedReceiver = strtolower(trim($expectedReceiver));
        $transfers = [];

        foreach ($logs as $log) {
            if (! is_array($log) || (bool) ($log['removed'] ?? false)) {
                continue;
            }

            $transactionHash = BscTransactionHash::normalize((string) ($log['transactionHash'] ?? ''));
            $blockNumber = $this->quantity($log['blockNumber'] ?? null);
            if ($transactionHash === null || $blockNumber === null) {
                continue;
            }

            $evidence = $this->decodeTransfer($log, $transactionHash, $blockNumber);
            if ($evidence !== null) {
                $transfers[] = $evidence;
            }
        }

        $exactTransfers = array_values(array_filter(
            $transfers,
            static fn (BscTransferEvidence $transfer): bool => hash_equals($expectedContract, $transfer->tokenContract)
                && hash_equals($expectedReceiver, $transfer->receiverAddress)
                && hash_equals($expectedAmountUnits, $transfer->amountUnits),
        ));

        return new BscTransferInspection('success', $transfers, $exactTransfers);
    }


    /** @param array<string, mixed> $log */
    private function decodeTransfer(array $log, string $transactionHash, int $blockNumber): ?BscTransferEvidence
    {
        $address = strtolower(trim((string) ($log['address'] ?? '')));
        $topics = $log['topics'] ?? null;
        if (preg_match('/^0x[0-9a-f]{40}$/', $address) !== 1 || ! is_array($topics) || count($topics) < 3) {
            return null;
        }

        $topic0 = strtolower(trim((string) ($topics[0] ?? '')));
        if ($topic0 !== BscRpcClient::TRANSFER_TOPIC) {
            return null;
        }

        $from = $this->indexedAddress($topics[1] ?? null);
        $to = $this->indexedAddress($topics[2] ?? null);
        $amount = $this->tokenUnits($log['data'] ?? null);
        if ($from === null || $to === null || $amount === null) {
            return null;
        }

        $logIndex = $this->quantity($log['logIndex'] ?? null) ?? 0;
        $logBlockHash = BscTransactionHash::normalize((string) ($log['blockHash'] ?? ''));

        return new BscTransferEvidence(
            txHash: $transactionHash,
            tokenContract: $address,
            receiverAddress: $to,
            fromAddress: $from,
            amountUnits: $amount,
            blockNumber: $blockNumber,
            blockHash: $logBlockHash,
            logIndex: $logIndex,
        );
    }

    private function receiptState(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            '0x1', '1' => 'success',
            '0x0', '0' => 'failed',
            default => 'invalid',
        };
    }

    private function indexedAddress(mixed $topic): ?string
    {
        $value = strtolower(trim((string) $topic));
        if (preg_match('/^0x[0-9a-f]{64}$/', $value) !== 1) {
            return null;
        }

        return '0x'.substr($value, -40);
    }

    private function tokenUnits(mixed $data): ?string
    {
        $value = strtolower(trim((string) $data));
        if (preg_match('/^0x[0-9a-f]{64}$/', $value) !== 1) {
            return null;
        }

        try {
            return BigInteger::fromBase(substr($value, 2), 16)->toBase(10);
        } catch (\Throwable) {
            return null;
        }
    }

    private function quantity(mixed $value): ?int
    {
        $value = strtolower(trim((string) $value));
        if (preg_match('/^0x[0-9a-f]+$/', $value) !== 1) {
            return null;
        }

        $hex = ltrim(substr($value, 2), '0');
        if ($hex === '' || strlen($hex) > 16) {
            return $hex === '' ? 0 : null;
        }

        $number = hexdec($hex);

        return is_int($number) ? $number : null;
    }
}
