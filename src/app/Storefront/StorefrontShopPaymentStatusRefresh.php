<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;
use App\Services\OrderLifecycle;
use App\Services\OrderLifecycleProjection;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use Throwable;

class StorefrontShopPaymentStatusRefresh
{
    public function __construct(
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirNativeQrisService $nativeQris,
        private readonly PakasirCallbackProcessor $processor,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontFunnelEvents $events,
        private readonly OrderLifecycle $orders,
        private readonly StorefrontShopPaymentRefreshCoordinator $coordinator,
        private readonly StorefrontPaymentTerminalCopy $terminalCopy,
    ) {}

    public function execute(Order $order): StorefrontShopSignedStatusRefreshResult
    {
        $payment = $this->latestPayment($order);

        if ($this->isBinancePayment($payment)) {
            $order->refresh();
            $payment->refresh();
            $projection = $this->orders->project($order, $payment);

            return StorefrontShopSignedStatusRefreshResult::refreshed(
                paymentStatus: $payment->status,
                orderStatus: $order->order_status,
                isPaid: $projection->isPaid,
                isTerminal: $this->state($projection) === StorefrontShopSignedInvoiceState::Terminal
                    || ($payment->binance_verification_mode === 'order_id'
                        && $payment->binance_verification_grace_until !== null
                        && ! $payment->binance_verification_grace_until->isFuture()
                        && ! $projection->isPaid),
                qrisReady: false,
                shouldReload: $projection->isPaid,
                redirectUrl: $this->invoiceUrls->show($order),
                message: $this->binanceStatusMessage($payment, $projection),
            );
        }

        if (! $this->isPakasirPayment($payment)) {
            return StorefrontShopSignedStatusRefreshResult::unsupportedPayment();
        }

        $wasQrisReady = $this->hasPayableNativeQris($payment);
        $qrisRecoveryFailed = false;
        $qrisRecoveredDuringRetry = false;

        $projection = $this->orders->project($order, $payment);
        if ($this->shouldRecoverQris($projection, $payment)) {
            try {
                $this->nativeQris->ensureCreated($payment);
            } catch (Throwable $exception) {
                report($exception);
                $qrisRecoveryFailed = true;
            }

            $payment->refresh();
            if (! $wasQrisReady && $this->hasPayableNativeQris($payment)) {
                $qrisRecoveredDuringRetry = true;
                $this->events->qrisCreated($order, $payment, [
                    'source' => 'status_retry',
                    'idempotent_reuse' => false,
                ]);
            }
        }

        if ($qrisRecoveryFailed && ! $this->hasPayableNativeQris($payment)) {
            $readiness = $this->qrisReadiness($this->orders->project($order, $payment), $payment);
            $this->events->checkoutSubmitted(null, $order, [
                'status_endpoint_result' => 'qris_not_ready',
                'qris_readiness_state' => $readiness['state'],
                'failure_kind' => 'qris_recovery_failed',
            ]);

            return StorefrontShopSignedStatusRefreshResult::qrisNotReady(
                title: $readiness['title'],
                message: $readiness['message'],
            );
        }

        if ($this->shouldRefreshFromProvider($projection, $payment)) {
            $lock = null;
            $skipProviderLookup = $this->coordinator->recentlyRefreshed($payment);

            if (! $skipProviderLookup) {
                $lock = $this->coordinator->acquire($payment);
                if ($lock === null) {
                    $this->events->checkoutSubmitted(null, $order, [
                        'status_endpoint_result' => 'in_progress',
                    ]);

                    return StorefrontShopSignedStatusRefreshResult::inProgress(
                        $this->coordinator->retryAfterSeconds(),
                    );
                }
            }

            try {
                if (! $skipProviderLookup) {
                    $this->processor->processReconciliationForPayment($payment, $this->pakasir->transactionDetail($payment));
                    $this->coordinator->markRecentlyRefreshed($payment);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->events->checkoutSubmitted(null, $order, [
                    'status_endpoint_result' => 'provider_failure',
                    'failure_kind' => $exception::class,
                ]);

                return StorefrontShopSignedStatusRefreshResult::retryableProviderFailure(
                    $this->coordinator->retryAfterSeconds(),
                );
            } finally {
                if ($lock !== null) {
                    $lock->release();
                }
            }
        }

        $order->refresh();
        $payment->refresh();
        $projection = $this->orders->project($order, $payment);
        $qrisReady = $this->hasPayableNativeQris($payment);
        if (! $wasQrisReady && $qrisReady && ! $qrisRecoveredDuringRetry) {
            $this->events->qrisCreated($order, $payment, [
                'source' => 'status_refresh',
                'idempotent_reuse' => false,
            ]);
        }
        if ($projection->isPaid) {
            $this->events->paymentVerified($order, $payment, [
                'source' => 'status_refresh',
                'status_endpoint_result' => 'paid',
            ]);
        }
        if ($projection->isTerminal) {
            $this->events->checkoutSubmitted(null, $order, [
                'status_endpoint_result' => 'terminal',
                'recovery_cause' => $projection->terminalCause,
            ]);
        }
        $shouldReload = ! $projection->isPaid
            && ! $projection->isTerminal
            && ! $wasQrisReady
            && $qrisReady;

        return StorefrontShopSignedStatusRefreshResult::refreshed(
            paymentStatus: $payment->status,
            orderStatus: $order->order_status,
            isPaid: $projection->isPaid,
            isTerminal: $this->state($projection) === StorefrontShopSignedInvoiceState::Terminal,
            qrisReady: $qrisReady,
            shouldReload: $shouldReload,
            redirectUrl: $this->invoiceUrls->show($order),
            message: $shouldReload ? 'QRIS dan total bayar final sudah siap. Invoice akan dimuat ulang.' : $this->statusMessage($order, $payment),
        );
    }

    private function latestPayment(Order $order): ?Payment
    {
        return $order->payments()->latest('created_at')->first();
    }

    private function shouldRefreshFromProvider(OrderLifecycleProjection $projection, Payment $payment): bool
    {
        return $this->hasPayableNativeQris($payment) && $projection->isPendingPayment;
    }

    private function shouldRecoverQris(OrderLifecycleProjection $projection, Payment $payment): bool
    {
        return $this->isPakasirPayment($payment)
            && $projection->isPendingPayment
            && ! $this->hasPayableNativeQris($payment);
    }

    private function isPakasirPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === 'pakasir';
    }

    private function isBinancePayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === 'manual'
            && strtoupper((string) $payment?->method) === 'BINANCE_PAY_ID';
    }

    private function binanceStatusMessage(Payment $payment, OrderLifecycleProjection $projection): string
    {
        if ($projection->isTerminal || $projection->isPaid) {
            return $this->terminalCopy->message($projection, $payment);
        }

        if ($payment->binance_auto_verification_enabled && $payment->binance_verification_mode === 'amount_unique') {
            return $payment->binance_verification_status === 'mismatch'
                ? 'The payment needs a manual review. Please contact support with your invoice number.'
                : 'We are checking Binance history for the exact amount and receiver. Your product will be delivered automatically when the transfer matches.';
        }

        return match (true) {
            $payment->binance_verification_status === 'checking' && $payment->binance_verification_grace_until?->isFuture() => 'Order ID received. We are checking your Binance payment securely.',
            $payment->binance_verification_status === 'not_found' && $payment->binance_verification_grace_until?->isFuture() => 'The payment is not visible yet. We will keep checking while the verification window is open.',
            $payment->binance_verification_status === 'mismatch' => 'The payment needs a manual review. Please contact support with your invoice number.',
            $payment->binance_verification_status === 'provider_error' && $payment->binance_verification_grace_until?->isFuture() => 'Your Order ID was received. Verification is temporarily delayed; we will keep checking securely.',
            in_array($payment->binance_verification_status, ['checking', 'not_found', 'provider_error'], true) => 'Automatic verification has closed. Please contact support with your payment proof.',
            default => 'Submit your Binance Pay Order ID after completing the payment.',
        };
    }

    private function hasPayableNativeQris(?Payment $payment): bool
    {
        return $payment?->isPayableNativePakasirQris() === true;
    }

    private function state(OrderLifecycleProjection $projection): StorefrontShopSignedInvoiceState
    {
        if ($projection->isPaid) {
            return StorefrontShopSignedInvoiceState::Paid;
        }

        if ($projection->isPendingPayment) {
            return StorefrontShopSignedInvoiceState::Pending;
        }

        return StorefrontShopSignedInvoiceState::Terminal;
    }

    private function statusMessage(Order $order, ?Payment $payment): string
    {
        return $this->terminalCopy->message($this->orders->project($order, $payment), $payment);
    }

    /**
     * @return array{state: string, title: string, message: string}
     */
    private function qrisReadiness(OrderLifecycleProjection $projection, ?Payment $payment): array
    {
        if (! $projection->isPendingPayment) {
            return ['state' => 'terminal', 'title' => 'QRIS tidak aktif', 'message' => 'Invoice ini tidak sedang menunggu pembayaran QRIS.'];
        }

        if (filled($payment?->provider_payment_number) && $payment?->provider_total_payment === null) {
            return ['state' => 'provider_total_pending', 'title' => 'Provider belum memberi nominal', 'message' => 'QRIS sudah dibuat, tetapi total bayar final dari provider belum tersedia. Jangan bayar total produk.'];
        }

        if (in_array($payment?->provider_create_state, ['outcome_unknown', 'provider_total_pending', 'financial_integrity_failed'], true)) {
            return ['state' => 'failed', 'title' => 'Gagal membuat QRIS', 'message' => 'QRIS belum siap dipakai. Coba buat ulang dari invoice ini atau hubungi bantuan jika tetap gagal.'];
        }

        return ['state' => 'creating', 'title' => 'Sedang membuat QRIS', 'message' => 'Provider sedang menyiapkan QRIS dan nominal final. Jangan bayar sebelum QRIS tampil.'];
    }
}
