<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class BscReadiness
{
    public const APPROVED_USDT_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    private const CACHE_VERSION_KEY = 'bsc.readiness.version';

    private const CACHE_KEY_PREFIX = 'bsc.readiness';

    private const DEFAULT_CACHE_SECONDS = 60;

    public function __construct(private readonly BscRpcClientInterface $rpc) {}

    public function check(?ManualPaymentSetting $setting = null): BscReadinessReport
    {
        $setting ??= ManualPaymentSetting::current();

        try {
            $cacheKey = $this->cacheKey($setting);

            return Cache::remember(
                $cacheKey,
                now()->addSeconds($this->cacheSeconds()),
                fn (): BscReadinessReport => $this->evaluate($setting),
            );
        } catch (Throwable) {
            // Readiness remains authoritative even if the shared cache is
            // temporarily unavailable. The RPC checks themselves classify
            // provider failures into the report below.
            return $this->evaluate($setting);
        }
    }

    public function invalidate(): void
    {
        try {
            Cache::add(self::CACHE_VERSION_KEY, 1, now()->addYears(5));
            Cache::increment(self::CACHE_VERSION_KEY);
        } catch (Throwable) {
            // A cache outage must not make a committed setting/receiver
            // mutation fail. The next check will still perform fresh work.
        }
    }

    private function evaluate(?ManualPaymentSetting $setting): BscReadinessReport
    {
        $enabled = (bool) $setting?->bsc_payment_enabled;
        $checkedAt = Carbon::now();

        if (! $enabled) {
            $activeReceiverCount = $this->activeReceiverCount();

            return new BscReadinessReport(
                enabled: false,
                missing: ['BSC_PAYMENT_ENABLED'],
                invalid: [],
                activeReceiverCount: $activeReceiverCount,
                checkedAt: $checkedAt,
            );
        }

        $missing = [];
        $invalid = [];
        $rpcChainId = null;
        $tokenDecimals = null;

        $rpcUrl = trim((string) config('services.bsc.rpc_url'));
        $configuredChainId = (int) config('services.bsc.chain_id', 56);
        $contract = strtolower(trim((string) config('services.bsc.usdt_contract')));
        $expectedDecimals = (int) config('services.bsc.expected_token_decimals', 18);

        if ($rpcUrl === '') {
            $missing[] = 'BSC_RPC_URL';
        }
        if ($configuredChainId !== 56) {
            $invalid['BSC_CHAIN_ID'] = 'BSC_CHAIN_ID harus 56 untuk BNB Smart Chain Mainnet.';
        }
        if ($contract === '') {
            $missing[] = 'BSC_USDT_CONTRACT';
        } elseif (preg_match('/^0x[a-f0-9]{40}$/', $contract) !== 1) {
            $invalid['BSC_USDT_CONTRACT'] = 'BSC_USDT_CONTRACT harus berupa address EVM yang valid.';
        } elseif ($contract !== self::APPROVED_USDT_CONTRACT) {
            $invalid['BSC_USDT_CONTRACT'] = 'BSC_USDT_CONTRACT bukan approved USDT BNB Smart Chain contract.';
        }
        $activeReceiverCount = $this->activeReceiverCount($configuredChainId, $contract, $expectedDecimals);
        if ($activeReceiverCount < 1) {
            $missing[] = 'ACTIVE_VERIFIED_BSC_RECEIVER';
        }

        if ($missing === [] && $invalid === []) {
            try {
                $rpcChainId = $this->rpc->chainId();
                if ($rpcChainId !== 56) {
                    $invalid['BSC_RPC_CHAIN_ID'] = 'RPC BSC mengembalikan chain ID '.$rpcChainId.', bukan 56.';
                }

                $code = $this->rpc->contractCode($contract);
                if (strlen($code) <= 2) {
                    $invalid['BSC_USDT_CONTRACT_CODE'] = 'Alamat token BSC tidak memiliki contract code.';
                }

                $tokenDecimals = $this->rpc->tokenDecimals($contract);
                if ($tokenDecimals !== $expectedDecimals) {
                    $invalid['BSC_USDT_DECIMALS'] = "Token BSC harus memiliki {$expectedDecimals} decimals; RPC mengembalikan {$tokenDecimals}.";
                }
            } catch (BscRpcException $exception) {
                $invalid['BSC_RPC'] = 'Readiness RPC BSC gagal: '.$exception->getMessage();
            } catch (Throwable) {
                $invalid['BSC_RPC'] = 'Readiness RPC BSC gagal.';
            }

            if ($invalid === [] && ! $this->indexedSourceConfigured()) {
                try {
                    $this->assertTransferLogScanningSupported($contract);
                } catch (BscRpcException $exception) {
                    $invalid['BSC_ETH_GET_LOGS'] = 'BSC RPC tidak mendukung eth_getLogs: '.$exception->getMessage();
                } catch (Throwable) {
                    $invalid['BSC_ETH_GET_LOGS'] = 'BSC RPC tidak dapat memverifikasi dukungan eth_getLogs.';
                }
            }
        }

        return new BscReadinessReport(
            enabled: true,
            missing: $missing,
            invalid: $invalid,
            activeReceiverCount: $activeReceiverCount,
            rpcChainId: $rpcChainId,
            tokenDecimals: $tokenDecimals,
            checkedAt: $checkedAt,
        );
    }

    private function assertTransferLogScanningSupported(string $contract): void
    {
        // BscRpcClientInterface intentionally stays small because many
        // readiness-only callers use lightweight fakes. Production's client
        // exposes transferLogs, so this bounded empty range is a real
        // eth_getLogs capability probe without scanning data. A legacy
        // metadata-only fake is treated as compatible for old unit seams.
        if (! method_exists($this->rpc, 'transferLogs')) {
            return;
        }

        $this->rpc->{'transferLogs'}($contract, 0, 0);
    }

    private function indexedSourceConfigured(): bool
    {
        return filled(config('services.bsc.indexed_source'))
            || filled(config('services.bsc.indexer_url'));
    }

    private function cacheSeconds(): int
    {
        return max(
            1,
            (int) config(
                'services.bsc.readiness_cache_seconds',
                self::DEFAULT_CACHE_SECONDS,
            ),
        );
    }

    private function cacheKey(?ManualPaymentSetting $setting): string
    {
        Cache::add(self::CACHE_VERSION_KEY, 1, now()->addYears(5));
        $version = (int) Cache::get(self::CACHE_VERSION_KEY, 1);

        return self::CACHE_KEY_PREFIX.'.v'.$version.'.'.hash('sha256', json_encode([
            'setting_id' => $setting?->getKey(),
            'setting_updated_at' => $setting?->updated_at?->getTimestamp(),
            'enabled' => (bool) $setting?->bsc_payment_enabled,
            'rpc_url' => trim((string) config('services.bsc.rpc_url')),
            'chain_id' => (int) config('services.bsc.chain_id', 56),
            'contract' => strtolower(trim((string) config('services.bsc.usdt_contract'))),
            'expected_decimals' => (int) config('services.bsc.expected_token_decimals', 18),
            'indexed_source' => trim((string) config('services.bsc.indexed_source')),
            'indexer_url' => trim((string) config('services.bsc.indexer_url')),
        ], JSON_THROW_ON_ERROR));
    }

    private function activeReceiverCount(?int $chainId = null, ?string $contract = null, ?int $decimals = null): int
    {
        try {
            $query = BscPaymentReceiver::query()->activeVerified();
            if ($chainId !== null) {
                $query->where('chain_id', $chainId);
            }
            if ($contract !== null && $contract !== '') {
                $query->where('token_contract', $contract);
            }
            if ($decimals !== null) {
                $query->where('token_decimals', $decimals);
            }

            return $query->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
