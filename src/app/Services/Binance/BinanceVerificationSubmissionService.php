<?php

namespace App\Services\Binance;

use App\Enums\PaymentStatus;
use App\Jobs\VerifyBinanceOrderId;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BinanceVerificationSubmissionService
{
    public function submit(string $orderId, string $candidate): array
    {
        $normalized = BinanceOrderId::normalize($candidate);
        if ($normalized === null) {
            return ['status' => 'invalid'];
        }

        $result = DB::transaction(function () use ($orderId, $normalized): array {
            $order = Order::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
            $payment = $order->payments()
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending)
                ->lockForUpdate()
                ->first();

            if (! $payment) {
                return ['status' => 'expired'];
            }

            if (! $payment->binance_auto_verification_enabled) {
                return ['status' => 'manual'];
            }

            if ($payment->binance_verification_mode !== 'order_id') {
                return ['status' => 'manual'];
            }

            $deadline = $payment->expired_at
                ?? $order->expires_at
                ?? $payment->created_at?->copy()->addMinutes(20)
                ?? now()->addMinutes(20);
            if (! $deadline->isFuture()) {
                return ['status' => 'expired'];
            }

            // A mismatch or exhausted verification window closes the grace
            // period. Do not accept another candidate after that point: it
            // would be persisted as `checking` but could never be processed
            // by the retry guard, leaving the payment orphaned indefinitely.
            if ($payment->binance_verification_grace_until?->isPast()) {
                return ['status' => 'grace_expired'];
            }

            if (in_array($payment->binance_verification_status, ['checking', 'not_found', 'provider_error'], true)
                && $payment->binance_order_id === $normalized
                && (int) $payment->binance_order_id_submission_version > 0
                && $payment->binance_verification_grace_until?->isFuture()) {
                return [
                    'status' => 'accepted',
                    'payment' => $payment->fresh(),
                    'version' => (int) $payment->binance_order_id_submission_version,
                    // A provider_error with no verification timestamp can be
                    // a queue-dispatch failure. Re-dispatch the same version;
                    // ShouldBeUnique suppresses duplicates when the original
                    // job actually exists.
                    'dispatch' => $payment->binance_verification_status === 'provider_error'
                        && $payment->binance_last_verified_at === null,
                ];
            }

            if ((int) $payment->binance_order_id_attempts >= BinanceOrderId::MAX_ATTEMPTS) {
                return ['status' => 'limit'];
            }

            $version = (int) $payment->binance_order_id_submission_version + 1;
            $graceUntil = $payment->binance_verification_grace_until;
            if ($graceUntil === null && $deadline !== null) {
                $graceUntil = $deadline->copy()->addMinutes(3);
            }

            $payment->update([
                'binance_order_id' => $normalized,
                'binance_order_id_attempts' => (int) $payment->binance_order_id_attempts + 1,
                'binance_order_id_submission_version' => $version,
                'binance_order_id_submitted_at' => now(),
                'binance_verification_grace_until' => $graceUntil,
                'binance_verification_status' => 'checking',
                'expired_at' => $payment->expired_at ?? $deadline,
            ]);

            return ['status' => 'accepted', 'payment' => $payment->fresh(), 'version' => $version];
        }, 5);

        if ($result['status'] === 'accepted' && ($result['dispatch'] ?? true)) {
            /** @var Payment $payment */
            $payment = $result['payment'];
            try {
                VerifyBinanceOrderId::dispatch($payment->id, (string) $payment->binance_order_id, (int) $payment->binance_order_id_attempts, 1, (int) $result['version'])
                    ->afterCommit()
                    ->onQueue((string) config('services.binance.verification_queue', 'binance-verification'));
            } catch (Throwable $exception) {
                // The payment row is already committed. Keep it recoverable by
                // reconciliation and let the caller show the same secure
                // checking state instead of turning a queue outage into a
                // failed submission/500 response.
                report($exception);
                Payment::query()
                    ->whereKey($payment->id)
                    ->where('binance_order_id_submission_version', (int) $result['version'])
                    ->update(['binance_verification_status' => 'provider_error']);
                $result['dispatch_failed'] = true;
            }
        }

        return $result;
    }
}
