<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Services\Bsc\BscOperationalAlertNotifier;
use App\Services\Bsc\BscOperationalMetrics;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscVerificationOutcome;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileBscPayments extends Command
{
    protected $signature = 'bsc:reconcile {--chunk=100}';

    protected $description = 'Verify active USDT BEP20 Payments by unique amount or submitted transaction hash.';

    public function handle(
        BscPaymentVerification $verification,
        BscReadiness $readiness,
        BscOperationalMetrics $metrics,
        BscOperationalAlertNotifier $alerts,
    ): int {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1_000) {
            $this->error('bsc:reconcile failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }

        $setting = ManualPaymentSetting::current();
        $readinessReport = $readiness->check($setting);
        $alerts->notifyReadiness($readinessReport);
        if ($setting?->bsc_payment_enabled && ! $readinessReport->isReady()) {
            $this->warn('bsc:reconcile readiness warning: '.$readinessReport->configurationMessage());
        }

        $backlog = $metrics->pendingVerification();
        $backlogThreshold = max(1, (int) config('services.bsc.pending_backlog_alert_threshold', 50));
        $alerts->notifyBacklog($backlog['pending'], $backlogThreshold, $backlog['oldest_age_seconds']);

        if (! $verification->enabled()) {
            $this->info('bsc:reconcile skipped: BSC RPC or approved contract is not configured.');

            return self::SUCCESS;
        }

        $confirmed = 0;
        $confirming = 0;
        $waiting = 0;
        $flagged = 0;
        $failed = 0;

        // Terminal Orders are excluded so reconciliation never rescans their transfer history.
        Payment::query()
            ->where('provider', 'manual')
            ->where('method', 'USDT_BEP20')
            ->where('status', PaymentStatus::Pending->value)
            ->whereHas('order', fn ($order) => $order->where('order_status', OrderStatus::PendingPayment->value))
            ->whereHas('bscDetail', function ($detail): void {
                $detail
                    ->where(function ($mode): void {
                        $mode
                            ->where('verification_mode', PaymentBscDetail::VERIFICATION_MODE_AMOUNT_UNIQUE)
                            ->orWhere(function ($legacy): void {
                                $legacy
                                    ->where('verification_mode', PaymentBscDetail::VERIFICATION_MODE_TRANSACTION_HASH)
                                    ->whereNotNull('current_tx_hash');
                            });
                    })
                    ->whereNotIn('verification_status', [
                        'confirmed',
                        'expired',
                        'manual_review',
                        'mismatch',
                        'transaction_already_used',
                    ])
                    ->where(function ($retry): void {
                        $retry
                            ->whereNull('last_verified_at')
                            ->orWhere(function ($recent): void {
                                $recent
                                    ->where('verification_status', '!=', 'provider_error')
                                    ->where('last_verified_at', '<=', now()->subMinute());
                            })
                            ->orWhere(function ($providerError): void {
                                $providerError
                                    ->where('verification_status', 'provider_error')
                                    ->where('last_verified_at', '<=', now()->subMinutes(5));
                            });
                    });
            })
            ->with(['bscDetail', 'order'])
            ->orderBy('created_at')
            ->limit($chunk)
            ->get()
            ->each(function (Payment $payment) use ($verification, $alerts, &$confirmed, &$confirming, &$waiting, &$flagged, &$failed): void {
                try {
                    $result = $verification->verify($payment);
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;

                    return;
                }

                $alerts->notifyVerification($payment, $result);

                match ($result->outcome) {
                    BscVerificationOutcome::Confirmed => $confirmed++,
                    BscVerificationOutcome::Confirming => $confirming++,
                    BscVerificationOutcome::Mismatch,
                    BscVerificationOutcome::TransactionAlreadyUsed,
                    BscVerificationOutcome::ManualReview => $flagged++,
                    default => $waiting++,
                };
            });

        $summary = "confirmed={$confirmed} confirming={$confirming} waiting={$waiting} flagged={$flagged} failed={$failed}";
        if ($failed > 0) {
            $this->error('bsc:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('bsc:reconcile completed: '.$summary);

        return self::SUCCESS;
    }
}
