<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Binance\BinanceAutoVerification;
use App\Services\Binance\BinancePayClient;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Telegram\TelegramBinanceVerificationEscalator;
use Illuminate\Console\Command;
use Throwable;

class ReconcileBinancePayments extends Command
{
    protected $signature = 'binance:reconcile {--chunk=100}';

    protected $description = 'Automatically confirm pending Binance Pay payments from the account trade history.';

    public function handle(
        BinancePayClient $client,
        BinanceAutoVerification $verification,
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
            ->whereNotNull('binance_order_id')
            ->whereNotNull('binance_verification_grace_until')
            ->where('binance_verification_grace_until', '>', now())
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
            $transactions = $client->payTransactions($pending->first()->created_at->getTimestampMs() - $skewMs);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('binance:reconcile failed: Binance Pay history could not be fetched.');

            return self::FAILURE;
        }

        $confirmed = 0;
        $waiting = 0;
        $flagged = 0;
        $failed = 0;

        foreach ($pending as $payment) {
            try {
                $result = $verification->matchAndConfirm($payment, $transactions);
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
