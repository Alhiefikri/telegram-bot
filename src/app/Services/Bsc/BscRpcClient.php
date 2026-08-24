<?php

namespace App\Services\Bsc;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class BscRpcClient implements BscRpcClientInterface
{
    private int $requestId = 0;

    public function chainId(): int
    {
        return $this->hexQuantityToInt((string) $this->call('eth_chainId'));
    }

    public function contractCode(string $contract): string
    {
        $result = $this->call('eth_getCode', [strtolower(trim($contract)), 'latest']);

        if (! is_string($result) || ! preg_match('/^0x[0-9a-fA-F]*$/', $result)) {
            throw new BscRpcException('BSC RPC returned invalid contract bytecode.');
        }

        return strtolower($result);
    }

    public function tokenDecimals(string $contract): int
    {
        // decimals() -> bytes4(keccak256("decimals()"))
        $result = $this->call('eth_call', [[
            'to' => strtolower(trim($contract)),
            'data' => '0x313ce567',
        ], 'latest']);

        $hex = $this->hexData((string) $result);
        if ($hex === '' || strlen($hex) > 64) {
            throw new BscRpcException('BSC RPC returned invalid token decimals metadata.');
        }

        $decimals = hexdec($hex);
        if ($decimals < 0 || $decimals > 255) {
            throw new BscRpcException('BSC token decimals metadata is outside the supported range.');
        }

        return $decimals;
    }

    public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
    {
        $hashValue = strtolower(trim($messageHashHex));
        $signatureValue = strtolower(trim($signature));
        $hash = str_starts_with($hashValue, '0x') ? substr($hashValue, 2) : $hashValue;
        $signatureHex = str_starts_with($signatureValue, '0x') ? substr($signatureValue, 2) : $signatureValue;
        if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1 || preg_match('/^[0-9a-f]+$/', $signatureHex) !== 1 || strlen($signatureHex) % 2 !== 0) {
            return false;
        }

        $signatureBytes = intdiv(strlen($signatureHex), 2);
        $signatureData = $signatureHex.str_repeat('0', (64 - (strlen($signatureHex) % 64)) % 64);
        $encodedSignature = str_pad(dechex($signatureBytes), 64, '0', STR_PAD_LEFT)
            .$signatureData;
        $data = '0x1626ba7e'
            .$hash
            .str_pad('40', 64, '0', STR_PAD_LEFT)
            .$encodedSignature;

        $result = $this->call('eth_call', [[
            'to' => strtolower(trim($contract)),
            'data' => $data,
        ], 'latest']);

        return is_string($result)
            && str_starts_with(strtolower($result), '0x1626ba7e');
    }

    private function call(string $method, array $params = []): mixed
    {
        $rpcUrl = trim((string) config('services.bsc.rpc_url'));
        if ($rpcUrl === '') {
            throw new BscRpcException('BSC_RPC_URL is not configured.');
        }

        try {
            $response = $this->request()->post($rpcUrl, [
                'jsonrpc' => '2.0',
                'id' => ++$this->requestId,
                'method' => $method,
                'params' => $params,
            ]);
        } catch (\Throwable $exception) {
            throw new BscRpcException('BSC RPC request failed.', 0, $exception);
        }

        if ($response->failed()) {
            throw new BscRpcException('BSC RPC returned HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new BscRpcException('BSC RPC returned invalid JSON.');
        }

        if (isset($payload['error'])) {
            $message = is_array($payload['error']) ? (string) ($payload['error']['message'] ?? 'RPC error') : 'RPC error';
            throw new BscRpcException('BSC RPC error: '.$message);
        }

        if (! array_key_exists('result', $payload)) {
            throw new BscRpcException('BSC RPC response has no result.');
        }

        return $payload['result'];
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout((float) config('services.bsc.rpc_timeout_seconds', 5.0))
            ->connectTimeout((float) config('services.bsc.rpc_connect_timeout_seconds', 2.0));
    }

    private function hexQuantityToInt(string $value): int
    {
        $hex = $this->hexData($value);
        if ($hex === '' || strlen($hex) > 8) {
            throw new BscRpcException('BSC RPC returned invalid chain ID.');
        }

        return (int) hexdec($hex);
    }

    private function hexData(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^0x([0-9a-fA-F]+)$/', $value, $matches) !== 1) {
            throw new BscRpcException('BSC RPC returned invalid hexadecimal data.');
        }

        $hex = ltrim(strtolower($matches[1]), '0');

        return $hex === '' ? '0' : $hex;
    }
}
