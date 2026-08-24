<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationOutcome;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileBscPayments extends Command
{
    protected $signature = 'bsc:reconcile {--chunk=100}';

    protected $description = 'Verify pending USDT BEP20 Payments from bounded BSC receipt and Transfer-log reads.';

    public function handle(BscPaymentVerification $verification): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1_000) {
            $this->error('bsc:reconcile failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }

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
            ->where('status', PaymentStatus::Pending->value)
            ->whereHas('bscDetail', function ($query): void {
                $query->whereNotIn('verification_status', [
                    'confirmed',
                    'expired',
                    'manual_review',
                    'mismatch',
                    'transaction_already_used',
                ]);
            })
            ->with(['bscDetail', 'order'])
            ->orderBy('created_at')
            ->limit($chunk)
            ->get()
            ->each(function (Payment $payment) use ($verification, &$confirmed, &$confirming, &$waiting, &$flagged, &$failed): void {
                try {
                    $result = $verification->verify($payment);
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;

                    return;
                }

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
