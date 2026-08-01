<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Telegram\TelegramManualPaymentIntents;
use Illuminate\Console\Command;

class ForwardPendingManualPaymentProofs extends Command
{
    protected $signature = 'telegram:forward-payment-proofs {--chunk=100}';

    protected $description = 'Forward stored manual payment proofs that have not reached the admin chat.';

    public function handle(TelegramManualPaymentIntents $payments): int
    {
        $forwarded = 0;
        $failed = 0;
        $cutoff = now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)));

        Payment::query()
            ->where('provider', 'manual')
            ->where(function ($query): void {
                $query
                    ->where('status', PaymentStatus::Pending)
                    ->orWhere(fn ($late) => $late
                        ->where('method', 'BINANCE_PAY_ID')
                        ->whereIn('status', [PaymentStatus::Expired, PaymentStatus::Failed]));
            })
            ->whereNotNull('payment_proof_file_id')
            ->whereNotNull('payment_proof_submitted_at')
            ->whereNull('payment_proof_forwarded_at')
            ->where(fn ($query) => $query
                ->whereNull('payment_proof_forwarding_started_at')
                ->orWhere('payment_proof_forwarding_started_at', '<=', $cutoff))
            ->with(['order.customer', 'order.orderItems'])
            ->chunkById((int) $this->option('chunk'), function ($proofs) use ($payments, &$forwarded, &$failed): void {
                foreach ($proofs as $proof) {
                    if ($proof->order === null) {
                        continue;
                    }

                    try {
                        if ($payments->forwardSubmittedManualPaymentProof($proof->order, $proof)) {
                            $forwarded++;
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                        $failed++;
                    }
                }
            });

        $this->info("telegram:forward-payment-proofs completed: forwarded={$forwarded} failed={$failed}");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
