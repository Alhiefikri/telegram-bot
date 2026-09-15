<?php

namespace App\Services\Bsc;

use App\Exceptions\InvalidCheckout;
use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class BscReadiness
{
    public const APPROVED_USDT_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    private const CACHE_VERSION_KEY = 'bsc.readiness.v2.version';

    private const CACHE_KEY_PREFIX = 'bsc.readiness';

    private const DEFAULT_CACHE_SECONDS = 60;

    private const READINESS_PROBE_RECEIVER = '0x0000000000000000000000000000000000000001';

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

    /**
     * Evaluate only local BSC configuration for advisory storefront pages.
     *
     * This path deliberately performs no RPC request. Checkout preparation
     * still uses check() before the authoritative transaction.
     */
    public function configuration(?ManualPaymentSetting $setting = null): BscReadinessReport
    {
        $setting ??= ManualPaymentSetting::current();

        return $this->evaluate($setting, probeProvider: false);
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

    private function evaluate(?ManualPaymentSetting $setting, bool $probeProvider = true): BscReadinessReport
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
                settingId: $setting?->getKey() !== null ? (int) $setting->getKey() : null,
                settingUpdatedAt: $this->settingUpdatedAt($setting),
                configurationFingerprint: $this->configurationFingerprint($setting),
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

        if ($probeProvider && $missing === [] && $invalid === []) {
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

        }

        return new BscReadinessReport(
            enabled: true,
            missing: $missing,
            invalid: $invalid,
            activeReceiverCount: $activeReceiverCount,
            rpcChainId: $rpcChainId,
            tokenDecimals: $tokenDecimals,
            checkedAt: $checkedAt,
            settingId: $setting?->getKey() !== null ? (int) $setting->getKey() : null,
            settingUpdatedAt: $this->settingUpdatedAt($setting),
            configurationFingerprint: $this->configurationFingerprint($setting),
        );
    }

    /**
     * Re-check only the database and runtime configuration snapshot inside
     * the checkout transaction. This method must stay free of RPC calls: the
     * remote readiness report is resolved before checkout acquires locks.
     */
    public function assertCheckoutSnapshot(?BscReadinessReport $report, ?ManualPaymentSetting $setting): void
    {
        if ($report === null || ! $report->isReady()) {
            $message = $report?->configurationMessage() ?? 'BSC readiness preflight is missing.';
            throw new InvalidCheckout('BSC payment rail is not ready. '.$message);
        }

        if ($setting === null || ! $setting->is_active || ! $setting->bsc_payment_enabled) {
            throw new InvalidCheckout('BSC payment rail is no longer enabled. Try again.');
        }

        if ($report->settingId !== (int) $setting->getKey()
            || $report->settingUpdatedAt !== $this->settingUpdatedAt($setting)
            || $report->configurationFingerprint !== $this->configurationFingerprint($setting)) {
            throw new InvalidCheckout('BSC payment rail readiness changed. Try again.');
        }

        $activeReceiverCount = $this->activeReceiverCount(
            (int) config('services.bsc.chain_id', 56),
            strtolower(trim((string) config('services.bsc.usdt_contract'))),
            (int) config('services.bsc.expected_token_decimals', 18),
        );
        if ($activeReceiverCount < 1 || $activeReceiverCount !== $report->activeReceiverCount) {
            throw new InvalidCheckout('BSC payment rail receiving addresses changed. Try again.');
        }
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

        return self::CACHE_KEY_PREFIX.'.v'.$version.'.'.$this->configurationFingerprint($setting);
    }

    private function configurationFingerprint(?ManualPaymentSetting $setting): string
    {
        return hash('sha256', json_encode([
            'setting_id' => $setting?->getKey(),
            'setting_updated_at' => $this->settingUpdatedAt($setting),
            'setting_active' => (bool) $setting?->is_active,
            'enabled' => (bool) $setting?->bsc_payment_enabled,
            'rpc_url' => trim((string) config('services.bsc.rpc_url')),
            'chain_id' => (int) config('services.bsc.chain_id', 56),
            'contract' => strtolower(trim((string) config('services.bsc.usdt_contract'))),
            'expected_decimals' => (int) config('services.bsc.expected_token_decimals', 18),
        ], JSON_THROW_ON_ERROR));
    }

    private function settingUpdatedAt(?ManualPaymentSetting $setting): ?string
    {
        return $setting?->updated_at?->format('Y-m-d H:i:s.uP');
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
