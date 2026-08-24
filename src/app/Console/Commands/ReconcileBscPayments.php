<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
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

    protected $description = 'Verify pending USDT BEP20 Payments from bounded BSC receipt and Transfer-log reads.';

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
        if ($setting?->bsc_payment_enabled) {
            $readinessReport = $readiness->check($setting);
            if (! $readinessReport->isReady()) {
                $alerts->notifyReadiness($readinessReport);
                $this->warn('bsc:reconcile readiness warning: '.$readinessReport->configurationMessage());
            }
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

        Payment::query()
            ->where('provider', 'manual')
            ->where('method', 'USDT_BEP20')
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending
                        ->where('status', PaymentStatus::Pending->value)
                        ->whereHas('bscDetail', function ($detail): void {
                            $detail->whereNotIn('verification_status', [
                                'confirmed',
                                'expired',
                                'manual_review',
                                'mismatch',
                                'transaction_already_used',
                            ]);
                        });
                })->orWhere(function ($terminal): void {
                    $terminal
                        ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                        ->where('created_at', '>=', now()->subHours(max(1, (int) config('services.bsc.late_payment_lookback_hours', 72))))
                        ->whereHas('bscDetail', function ($detail): void {
                            $detail
                                ->whereIn('verification_status', [
                                    'tx_submitted',
                                    'confirming',
                                    'provider_error',
                                    'expired',
                                    'manual_review',
                                ])
                                ->where(function ($recent): void {
                                    $recent->whereNull('last_verified_at')
                                        ->orWhere('last_verified_at', '<=', now()->subMinutes(5));
                                });
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
