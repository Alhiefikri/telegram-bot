<?php

namespace App\Services\Checkout;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Admin\AdminPaymentOperations;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahReconciliationOutcome;
use App\Services\Sakurupiah\SakurupiahReconciliationQuery;
use App\Services\Telegram\TelegramAdminAccess;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\WijayaPay\WijayaPayCallbackProcessor;
use App\Services\WijayaPay\WijayaPayReconciliationOutcome;
use App\Services\WijayaPay\WijayaPayReconciliationQuery;
use App\Support\PaymentProvider;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CheckoutManualAlternative
{
    public function manualAvailable(): bool
    {
        return is_readable(public_path('qr-lhie.jpg'))
            && preg_match('/^[1-9][0-9]*$/D', (string) app(TelegramAdminAccess::class)->adminChatId()) === 1;
    }

    public function isEligible(Order $order, Payment $payment): bool
    {
        return $payment->manual_alternative_retirement_token === null && $this->pendingEligible($order, $payment);
    }

    private function pendingEligible(Order $order, Payment $payment): bool
    {
        return $this->manualAvailable()
            && (string) $payment->order_id === (string) $order->id
            && $order->order_status === OrderStatus::PendingPayment
            && $order->payment_status === PaymentStatus::Pending
            && $payment->status === PaymentStatus::Pending
            && $payment->manual_alternative_at === null
            && $payment->manual_alternative_for_payment_id === null
            && $payment->currency === 'IDR'
            && $order->currency === 'IDR'
            && (int) $payment->amount === (int) $order->total_amount
            && $payment->amount > 0
            && in_array($payment->provider, [PaymentProvider::WIJAYAPAY, PaymentProvider::SAKURUPIAH, PaymentProvider::KLIKQRIS, PaymentProvider::PAKASIR], true)
            && in_array(strtoupper((string) $payment->method), ['QRIS', 'QRISC', 'QRISMU', 'QRIS2'], true)
            && ($order->expires_at === null || $order->expires_at->isFuture())
            && ($payment->expired_at === null || $payment->expired_at->isFuture())
            && ($payment->provider_expires_at === null || $payment->provider_expires_at->isFuture());
    }

    /** A fresh provider observation is required; cached local pending is not evidence. */
    public function recheck(Payment $payment): string
    {
        $payment->refresh();
        if ($payment->status === PaymentStatus::Paid) {
            return 'paid';
        }
        if ($payment->manual_alternative_at !== null) {
            return 'switched';
        }
        if ($payment->provider_create_state === 'create_failed' && blank($payment->provider_reference)
            && ! $payment->paymentEvents()->whereIn('provider_status', ['paid', 'berhasil', 'SUCCESS', 'completed'])->exists()) {
            return 'unpaid';
        }
        try {
            $outcome = match ($payment->provider) {
                PaymentProvider::WIJAYAPAY => app(WijayaPayCallbackProcessor::class)->reconcilePayment($payment),
                PaymentProvider::SAKURUPIAH => app(SakurupiahCallbackProcessor::class)->reconcilePayment($payment),
                default => null,
            };
            if ($outcome !== null) {
                if ($payment->fresh()->status === PaymentStatus::Paid) {
                    return 'paid';
                }

                $hasSuccessIndication = match ($payment->provider) {
                    PaymentProvider::WIJAYAPAY => app(WijayaPayReconciliationQuery::class)->hasSuccessIndication($payment),
                    PaymentProvider::SAKURUPIAH => app(SakurupiahReconciliationQuery::class)->hasSuccessIndication($payment),
                    default => false,
                };
                if ($hasSuccessIndication) {
                    return 'unknown';
                }

                return in_array($outcome, [WijayaPayReconciliationOutcome::Pending, SakurupiahReconciliationOutcome::Pending], true)
                    && filled($payment->fresh()->provider_reference) ? 'unpaid' : 'unknown';
            }
            $operations = app(AdminPaymentOperations::class);
            if (! $operations->canReconcile($payment)) {
                return 'unknown';
            }
            $event = $operations->reconcile($payment);
            if ($payment->fresh()->status === PaymentStatus::Paid) {
                return 'paid';
            }

            return in_array(strtolower((string) $event->provider_status), ['pending'], true) ? 'unpaid' : 'unknown';
        } catch (\Throwable $exception) {
            report($exception);

            return 'unknown';
        }
    }

    /** @return array{status: string, order?: Order, payment?: Payment} */
    public function switch(Customer $customer, Payment $payment, Closure $retireAutomaticQr): array
    {
        $order = $payment->order;
        if ($order === null || (string) $order->customer_id !== (string) $customer->id) {
            return ['status' => 'unavailable'];
        }
        if (! $this->pendingEligible($order, $payment)) {
            return ['status' => $payment->manual_alternative_at !== null ? 'switched' : 'unavailable'];
        }
        $status = $this->recheck($payment);
        if ($status !== 'unpaid') {
            return ['status' => $status];
        }

        $observation = $this->creationIdentity($payment);
        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($customer, $payment, $observation, $token): bool {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $original = $payments->firstWhere('id', $payment->id);
            if ((string) $order->customer_id !== (string) $customer->id || $original === null || ! $this->pendingEligible($order, $original)
                || $original->provider_create_state === 'creating'
                || $observation !== $this->creationIdentity($original)
                || ($original->manual_alternative_retirement_token !== null
                    && $original->manual_alternative_retirement_started_at?->gt(now()->subSeconds(60)))
                || $payments->contains(fn (Payment $candidate): bool => $candidate->id !== $original->id)) {
                return false;
            }
            $original->update(['manual_alternative_retirement_token' => $token, 'manual_alternative_retirement_started_at' => now()]);

            return true;
        }, 5);
        if (! $claimed) {
            return ['status' => 'unknown'];
        }

        try {
            $retireAutomaticQr($order->fresh());
        } catch (RequestException $exception) {
            if (in_array($exception->response->status(), [400, 403], true)) {
                Payment::query()->whereKey($payment->id)->where('manual_alternative_retirement_token', $token)
                    ->update(['manual_alternative_retirement_token' => null, 'manual_alternative_retirement_started_at' => null]);
            }
            throw $exception;
        }

        return DB::transaction(function () use ($customer, $payment, $token): array {
            $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->firstOrFail();
            $payments = Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            $original = $payments->firstWhere('id', $payment->id);
            if ($original === null || $original->manual_alternative_retirement_token !== $token
                || ! $original->manual_alternative_retirement_started_at?->gt(now()->subSeconds(60))
                || (string) $order->customer_id !== (string) $customer->id || ! $this->pendingEligible($order, $original)
                || $payments->count() !== 1) {
                return ['status' => $original?->status === PaymentStatus::Paid ? 'paid' : 'unavailable'];
            }
            $manual = Payment::query()->create([
                'order_id' => $order->id,
                'manual_alternative_for_payment_id' => $original->id,
                'provider' => PaymentProvider::MANUAL,
                'method' => 'QR_MANUAL',
                'merchant_ref' => 'MANUAL-'.$original->id,
                'amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => PaymentStatus::Pending,
                'expired_at' => $order->expires_at,
            ]);
            $original->update(['manual_alternative_at' => now(), 'manual_alternative_retirement_token' => null, 'manual_alternative_retirement_started_at' => null]);
            $order->forceFill([
                'manual_checkout_customer_notified_at' => null,
                'manual_checkout_customer_notification_deleted_at' => now(),
            ])->save();
            app(TelegramOutboundEffectService::class)->recordManualCheckout($order, $manual, $customer);

            return ['status' => 'switched', 'order' => $order->fresh(['customer', 'payments', 'orderItems']), 'payment' => $manual];
        }, 5);
    }

    private function creationIdentity(Payment $payment): array
    {
        return array_intersect_key($payment->getRawOriginal(), array_flip([
            'provider_create_state', 'provider_reference', 'provider_create_attempted_at', 'provider_created_at',
        ]));
    }
}
