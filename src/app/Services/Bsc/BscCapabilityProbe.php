<?php

namespace App\Services\Bsc;

use Illuminate\Support\Carbon;
use Throwable;

final class BscCapabilityProbe
{
    public const EXPECTED_CHAIN_ID = 56;

    private const PROBE_RECEIVER = '0x0000000000000000000000000000000000000001';

    public function __construct(private readonly BscVerificationRpcClientInterface $rpc) {}

    public function probe(): BscCapabilityReport
    {
        $expectedContract = strtolower(trim((string) config('services.bsc.usdt_contract', BscReadiness::APPROVED_USDT_CONTRACT)));
        if ($expectedContract === '') {
            $expectedContract = BscReadiness::APPROVED_USDT_CONTRACT;
        }
        $expectedDecimals = (int) config('services.bsc.expected_token_decimals', 18);

        $capabilities = [];
        $chainId = null;
        $tokenDecimals = null;
        $expectedChainId = (int) config('services.bsc.chain_id', self::EXPECTED_CHAIN_ID);

        try {
            $chainId = $this->rpc->chainId();
            if ($expectedChainId !== self::EXPECTED_CHAIN_ID) {
                $capabilities['chain_id'] = $this->unsupported('Konfigurasi aplikasi bukan BNB Smart Chain Mainnet (56).');
            } else {
                $capabilities['chain_id'] = $chainId === $expectedChainId
                    ? $this->verified('RPC melaporkan chain ID '.self::EXPECTED_CHAIN_ID.'.')
                    : $this->unsupported("RPC melaporkan chain ID {$chainId}, bukan ".self::EXPECTED_CHAIN_ID.'.');
            }
        } catch (Throwable $exception) {
            $capabilities['chain_id'] = $this->classifyFailure($exception);
        }

        try {
            $code = $this->rpc->contractCode($expectedContract);
            $capabilities['token_contract_code'] = strlen($code) > 2
                ? $this->verified('Alamat token memiliki contract code.')
                : $this->unsupported('Alamat token tidak memiliki contract code.');
        } catch (Throwable $exception) {
            $capabilities['token_contract_code'] = $this->classifyFailure($exception);
        }

        try {
            $tokenDecimals = $this->rpc->tokenDecimals($expectedContract);
            $capabilities['token_decimals'] = $tokenDecimals === $expectedDecimals
                ? $this->verified("Token decimals sesuai ({$expectedDecimals}).")
                : $this->unsupported("Token decimals {$tokenDecimals} tidak sesuai expected {$expectedDecimals}.");
        } catch (Throwable $exception) {
            $capabilities['token_decimals'] = $this->classifyFailure($exception);
        }

        $finalized = $this->probeFinalizedBlock();
        $capabilities['finalized_block'] = $finalized['entry'];
        $capabilities['canonical_receipt'] = $this->probeCanonicalReceipt($finalized);
        $capabilities['transfer_logs'] = $this->probeTransferLogs($expectedContract);

        return new BscCapabilityReport(
            capabilities: $capabilities,
            chainId: $chainId,
            tokenDecimals: $tokenDecimals,
            checkedAt: Carbon::now(),
        );
    }

    /**
     * @return array{entry: array{status: BscCapabilityStatus, detail: string}, number: ?int, hash: ?string, transactions: list<string>}
     */
    private function probeFinalizedBlock(): array
    {
        try {
            $block = $this->rpc->blockByNumber('finalized');
            $latest = $this->rpc->blockByNumber('latest');
        } catch (Throwable $exception) {
            return ['entry' => $this->classifyFailure($exception), 'number' => null, 'hash' => null, 'transactions' => []];
        }

        $latestNumber = is_array($latest) ? $this->quantity($latest['number'] ?? null) : null;
        if ($latest === null || $latestNumber === null) {
            return ['entry' => $this->unknown('Latest block tidak tersedia; kedalaman finality tidak dapat dibuktikan.'), 'number' => null, 'hash' => null, 'transactions' => []];
        }

        if ($block === null) {
            return ['entry' => $this->unsupported('RPC mengembalikan null untuk tag finalized.'), 'number' => null, 'hash' => null, 'transactions' => []];
        }

        $number = $this->quantity($block['number'] ?? null);
        $hash = $this->hexHash32($block['hash'] ?? null);
        if ($number === null || $hash === null) {
            return ['entry' => $this->unknown('Block finalized tidak lengkap (number/hash).'), 'number' => null, 'hash' => null, 'transactions' => []];
        }

        if ($number >= $latestNumber) {
            return ['entry' => $this->unknown('Tag finalized tidak berada di belakang head; finality tidak dapat dibuktikan.'), 'number' => null, 'hash' => null, 'transactions' => []];
        }

        $transactions = [];
        foreach (is_array($block['transactions'] ?? null) ? $block['transactions'] : [] as $transaction) {
            $normalized = $this->hexHash32($transaction);
            if ($normalized !== null) {
                $transactions[] = $normalized;
            }
        }

        return [
            'entry' => $this->verified('Tag finalized mengembalikan block lengkap di belakang head.'),
            'number' => $number,
            'hash' => $hash,
            'transactions' => $transactions,
        ];
    }

    /**
     * @param  array{entry: array{status: BscCapabilityStatus, detail: string}, number: ?int, hash: ?string, transactions: list<string>}  $finalized
     * @return array{status: BscCapabilityStatus, detail: string}
     */
    private function probeCanonicalReceipt(array $finalized): array
    {
        if ($finalized['number'] === null || $finalized['hash'] === null) {
            return $this->unknown('Finalized block facts tidak tersedia; canonical receipt tidak dapat dibuktikan.');
        }

        $transaction = $finalized['transactions'][0] ?? null;
        if ($transaction === null) {
            return $this->unknown('Probe block finalized tidak memuat transaksi; canonical receipt tidak dapat dibuktikan.');
        }

        try {
            $receipt = $this->rpc->transactionReceipt($transaction);
        } catch (Throwable $exception) {
            return $this->classifyFailure($exception);
        }

        if ($receipt === null) {
            return $this->unknown('Receipt transaksi finalized tidak tersedia; canonical receipt tidak dapat dibuktikan.');
        }

        $status = strtolower(trim((string) ($receipt['status'] ?? '')));
        $receiptNumber = $this->quantity($receipt['blockNumber'] ?? null);
        $receiptBlockHash = $this->hexHash32($receipt['blockHash'] ?? null);
        $receiptTransactionHash = $this->hexHash32($receipt['transactionHash'] ?? null);
        if (! in_array($status, ['0x0', '0x1'], true)
            || $receiptNumber === null
            || $receiptBlockHash === null
            || $receiptTransactionHash === null) {
            return $this->unknown('Receipt canonical tidak lengkap (status/number/hash).');
        }

        if (! hash_equals($transaction, $receiptTransactionHash)) {
            return $this->unsupported('Receipt canonical tidak merujuk transaksi yang diminta.');
        }

        if ($receiptNumber !== $finalized['number'] || ! hash_equals($finalized['hash'], $receiptBlockHash)) {
            return $this->unsupported('Receipt canonical tidak konsisten dengan block finalized.');
        }

        return $this->verified('Receipt canonical konsisten dengan block finalized.');
    }

    /** @return array{status: BscCapabilityStatus, detail: string} */
    private function probeTransferLogs(string $expectedContract): array
    {
        if (! $this->rpc instanceof BscTransferLogRpcClientInterface) {
            return $this->unknown('RPC client tidak mendukung pembacaan transfer log.');
        }

        try {
            $latestBlock = $this->rpc->latestBlockNumber();
            $chunkSize = max(1, (int) config('services.bsc.log_scan_chunk_blocks', 1_000));
            $this->rpc->transferLogs(
                tokenContract: $expectedContract,
                receiverAddress: self::PROBE_RECEIVER,
                fromBlock: max(0, $latestBlock - $chunkSize + 1),
                toBlock: $latestBlock,
            );

            return $this->verified('Bounded eth_getLogs transfer filter diterima.');
        } catch (Throwable $exception) {
            return $this->classifyFailure($exception);
        }
    }

    /** @return array{status: BscCapabilityStatus, detail: string} */
    private function verified(string $detail): array
    {
        return ['status' => BscCapabilityStatus::Verified, 'detail' => $detail];
    }

    /** @return array{status: BscCapabilityStatus, detail: string} */
    private function unsupported(string $detail): array
    {
        return ['status' => BscCapabilityStatus::Unsupported, 'detail' => $detail];
    }

    /** @return array{status: BscCapabilityStatus, detail: string} */
    private function unknown(string $detail): array
    {
        return ['status' => BscCapabilityStatus::Unknown, 'detail' => $detail];
    }

    /** @return array{status: BscCapabilityStatus, detail: string} */
    private function classifyFailure(Throwable $exception): array
    {
        if ($exception instanceof BscRpcException
            && preg_match('/method not found|not exist|not available|not supported|unsupported|does not support/i', $exception->getMessage()) === 1) {
            return $this->unsupported('RPC tidak mendukung capability yang diperlukan: '.$this->safeMessage($exception).'.');
        }

        return $this->unknown('Probe RPC gagal: '.$this->safeMessage($exception).'.');
    }

    private function safeMessage(Throwable $exception): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');

        return $message === '' ? $exception::class : mb_strimwidth($message, 0, 160, '…');
    }

    private function quantity(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));
        if (preg_match('/^0x[0-9a-f]+$/', $value) !== 1) {
            return null;
        }

        $hex = ltrim(substr($value, 2), '0');
        if ($hex === '') {
            return 0;
        }
        if (strlen($hex) > 16) {
            return null;
        }

        $number = hexdec($hex);

        return is_int($number) ? $number : null;
    }

    private function hexHash32(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^0x[0-9a-f]{64}$/', $value) === 1 ? $value : null;
    }
}
