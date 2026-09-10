<?php

namespace App\Console\Commands;

use App\Enums\KlikQrisEnvironment;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\StoreBalanceTopUp;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileStoreBalanceTopUps extends Command
{
    private const MAX_CHUNK = 1000;

    private const MAX_TOP_UPS = 100;

    protected $signature = 'klikqris:reconcile-balance-top-ups {--chunk=100} {--max-top-ups=10}';

    protected $description = 'Reconcile Store Balance top-ups from authoritative KlikQRIS transaction status.';

    public function handle(
        StoreBalanceTopUpService $topUps,
        KlikQrisProviderReadiness $readiness,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundDispatcher,
    ): int {
        $chunk = $this->validatedInteger('chunk', self::MAX_CHUNK);
        $maxTopUps = $this->validatedInteger('max-top-ups', self::MAX_TOP_UPS);
        if ($chunk === null || $maxTopUps === null) {
            $this->error('klikqris:reconcile-balance-top-ups failed: chunk must be 1-1000 and max-top-ups must be 1-100.');

            return self::FAILURE;
        }

        $environment = KlikQrisEnvironment::runtime();
        $report = $readiness->forEnvironment($environment);
        if (! $report->isReady()) {
            if ($readiness->disabledForEnvironment($environment)) {
                $this->info('klikqris:reconcile-balance-top-ups skipped safely: KlikQRIS is disabled for this environment.');

                return self::SUCCESS;
            }

            $this->error('klikqris:reconcile-balance-top-ups failed: active KlikQRIS environment is not ready.');

            return self::FAILURE;
        }

        $selected = 0;
        $payable = 0;
        $paid = 0;
        $uncertain = 0;
        $failed = 0;

        $candidates = StoreBalanceTopUp::query()
            ->where('provider', PaymentProvider::KLIKQRIS)
            ->whereNotIn('status', [
                StoreBalanceTopUpStatus::Paid->value,
                StoreBalanceTopUpStatus::Expired->value,
            ])
            ->whereNotNull('provider_create_state')
            ->where('provider_create_state', '!=', 'create_failed')
            ->orderBy('id')
            ->limit($chunk)
            ->get();

        foreach ($candidates as $topUp) {
            if ($selected >= $maxTopUps) {
                break;
            }

            $selected++;
            try {
                $result = $topUps->reconcile($topUp);
                $topUp->refresh();

                if ($topUp->status === StoreBalanceTopUpStatus::Paid) {
                    $paid++;
                } elseif ($result->isPayable()) {
                    $payable++;
                    $effect = $outboundEffects->recordBalanceTopUpInstructions(
                        $topUp->fresh(['customer']),
                        $topUp->customer,
                    );
                    if ($effect !== null) {
                        $outboundDispatcher->dispatch($effect);
                    }
                } elseif ($result->outcome() === KlikQrisNativeQrisOutcome::RecoveryPending) {
                    $uncertain++;
                }
            } catch (Throwable $exception) {
                $failed++;
                $this->error('Store Balance top-up reconciliation failed #'.$topUp->getKey().': '.$exception::class);
            }
        }

        $summary = "selected={$selected} payable={$payable} paid={$paid} uncertain={$uncertain} failed={$failed}";
        if ($failed > 0) {
            $this->error('klikqris:reconcile-balance-top-ups completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('klikqris:reconcile-balance-top-ups completed: '.$summary);

        return self::SUCCESS;
    }

    private function validatedInteger(string $option, int $max): ?int
    {
        $value = filter_var($this->option($option), FILTER_VALIDATE_INT);

        return $value === false || $value < 1 || $value > $max ? null : $value;
    }
}
