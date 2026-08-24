<?php

namespace App\Services\Bsc;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use Illuminate\Support\Carbon;
use Throwable;

final class BscReadiness
{
    public const APPROVED_USDT_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    public function __construct(private readonly BscRpcClientInterface $rpc) {}

    public function check(?ManualPaymentSetting $setting = null): BscReadinessReport
    {
        $setting ??= ManualPaymentSetting::current();
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
