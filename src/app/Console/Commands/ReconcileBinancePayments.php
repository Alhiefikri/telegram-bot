<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceTransactionHistoryReader;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Telegram\TelegramBinanceVerificationEscalator;
use Illuminate\Console\Command;
use Throwable;

class ReconcileBinancePayments extends Command
{
    protected $signature = 'binance:reconcile {--chunk=100}';

    protected $description = 'Automatically confirm pending Binance Pay payments from the account trade history.';

    public function handle(
        BinanceTransactionHistoryReader $history,
        BinancePaymentVerification $verification,
        TelegramBinanceVerificationEscalator $escalator,
    ): int {
        if (! $verification->enabled()) {
            $this->info('binance:reconcile skipped: Binance Pay API credentials are not configured.');

            return self::SUCCESS;
        }

        $pending = Payment::query()
            ->where('provider', 'manual')
            ->where('method', 'BINANCE_PAY_ID')
            ->where('status', PaymentStatus::Pending->value)
            ->where('binance_auto_verification_enabled', true)
            ->where(function ($query): void {
                $query->where(function ($orderIdQuery): void {
                    $orderIdQuery->where('binance_verification_mode', 'order_id')
                        ->whereNotNull('binance_order_id')
                        ->whereIn('binance_verification_status', ['awaiting_submission', 'checking', 'not_found', 'provider_error']);
                })->orWhere(function ($legacyQuery): void {
                    $legacyQuery->where('binance_verification_mode', 'amount_unique')
                        ->where(function ($query): void {
                            $query->whereNull('expired_at')->orWhere('expired_at', '>', now());
                        });
                });
            })
            ->whereNull('provider_reference')
            ->orderBy('created_at')
            ->limit(max(1, (int) $this->option('chunk')))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('binance:reconcile completed cleanly: no pending Binance payments.');

            return self::SUCCESS;
        }

        try {
            $skewMs = max(0, (int) config('services.binance.verify_clock_skew_seconds', 120)) * 1000;
            $historyResult = $history->read(
                $pending->first()->created_at->getTimestampMs() - $skewMs,
                now()->getTimestampMs(),
            );
        } catch (Throwable $exception) {
            report($exception);
            $this->error('binance:reconcile failed: Binance Pay history could not be fetched.');

            return self::FAILURE;
        }

        if (! $historyResult->complete) {
            $this->warn('binance:reconcile waiting: Binance Pay history window was too large to verify safely.');

            return self::SUCCESS;
        }

        $transactions = $historyResult->transactions;

        $confirmed = 0;
        $waiting = 0;
        $flagged = 0;
        $failed = 0;

        foreach ($pending as $payment) {
            try {
                $result = $verification->verify($payment, $transactions);
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error('Binance reconciliation failed for payment '.$payment->id.'.');

                continue;
            }

            match (true) {
                $result->confirmed() => $confirmed++,
                $result->needsAdminAttention() => $flagged++,
                default => $waiting++,
            };

            if ($result->outcome === BinanceVerificationOutcome::TransactionAlreadyUsed) {
                $this->warn('Payment '.$payment->id.' matched an already-claimed Binance transaction.');
            }

            if ($result->needsAdminAttention()) {
                $escalator->notify($payment->order()->firstOrFail(), $payment, $result);
            }
        }

        $summary = "confirmed={$confirmed} waiting={$waiting} flagged={$flagged} failed={$failed}";

        if ($failed > 0) {
            $this->error('binance:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('binance:reconcile completed: '.$summary);

        return self::SUCCESS;
    }
}
