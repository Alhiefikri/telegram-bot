<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\OrderLifecycle;
use App\Services\OrderLifecycleProjection;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\ProviderVerification\ProviderVerificationProfile;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Support\PaymentProvider;
use Throwable;

class StorefrontShopPaymentStatusRefresh
{
    public function __construct(
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirNativeQrisService $nativeQris,
        private readonly PakasirCallbackProcessor $processor,
        private readonly KlikQrisNativeQrisService $klikQrisNativeQris,
        private readonly KlikQrisCallbackProcessor $klikQrisProcessor,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontFunnelEvents $events,
        private readonly OrderLifecycle $orders,
        private readonly StorefrontShopPaymentRefreshCoordinator $coordinator,
        private readonly BinancePaymentVerification $verification,
        private readonly StorefrontPaymentTerminalCopy $terminalCopy,
    ) {}

    public function execute(Order $order): StorefrontShopSignedStatusRefreshResult
    {
        $payment = $this->latestPayment($order);

        if ($this->isBinancePayment($payment)) {
            $order->refresh();
            $payment->refresh();
            $projection = $this->orders->project($order, $payment);
            $verificationStatus = $this->verification->status($payment);

            if ($this->shouldVerifyBinance($projection, $verificationStatus)) {
                $submissionVersion = $verificationStatus->mode === 'order_id'
                    ? (int) $payment->binance_order_id_submission_version
                    : 0;
                $result = $this->verification->verify($payment, null, $submissionVersion);
                $this->verification->recordOutcome($payment, $result, $submissionVersion);
                $order->refresh();
                $payment->refresh();
                $projection = $this->orders->project($order, $payment);
                $verificationStatus = $this->verification->status($payment);
            }

            return StorefrontShopSignedStatusRefreshResult::refreshed(
                paymentStatus: $payment->status,
                orderStatus: $order->order_status,
                isPaid: $projection->isPaid,
                isTerminal: $this->state($projection) === StorefrontShopSignedInvoiceState::Terminal
                    || ($verificationStatus->mode === 'order_id'
                        && $verificationStatus->closed()
                        && ! $projection->isPaid),
                qrisReady: false,
                shouldReload: $projection->isPaid,
                redirectUrl: $this->invoiceUrls->show($order),
                message: $this->terminalCopy->binanceStatusMessage($projection, $payment, $verificationStatus),
            );
        }

        if (! $this->isAutomaticQrisPayment($payment)) {
            return StorefrontShopSignedStatusRefreshResult::unsupportedPayment();
        }

        $wasQrisReady = $this->isNativeQrisPayable($payment);
        $qrisRecoveryFailed = false;
        $qrisRecoveredDuringRetry = false;

        $projection = $this->orders->project($order, $payment);
        $recoveryOutcome = null;
        if ($this->shouldRecoverQris($projection, $payment)) {
            try {
                $recoveryOutcome = $this->attemptNativeQrisRecovery($payment);
            } catch (Throwable $exception) {
                report($exception);
                $qrisRecoveryFailed = true;
            }

            $payment->refresh();
            $qrisRecoveredDuringRetry = ! $wasQrisReady && $this->isPayableOutcome($recoveryOutcome, $payment);
            if ($qrisRecoveredDuringRetry) {
                $this->events->qrisCreated($order, $payment, [
                    'source' => 'status_retry',
                    'idempotent_reuse' => false,
                ]);
            }

            if (! $qrisRecoveryFailed
                && $this->isKlikQrisPayment($payment)
                && $recoveryOutcome !== null
                && ! in_array($recoveryOutcome, [KlikQrisNativeQrisOutcome::Payable, KlikQrisNativeQrisOutcome::ProviderTotalPending], true)) {
                $qrisRecoveryFailed = true;
            }
        }

        if ($qrisRecoveryFailed && ! $this->isNativeQrisPayable($payment)) {
            $readiness = $this->qrisReadiness($this->orders->project($order, $payment), $payment, $recoveryOutcome);
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

            try {
                if (! $skipProviderLookup && $this->isKlikQrisPayment($payment)) {
                    $lookupCompleted = $this->klikQrisNativeQris->tryWithPaymentLock($payment, function () use ($payment): bool {
                        ProviderVerificationProfiles::using(ProviderVerificationProfile::Interactive, fn () => $this->processProviderReconciliation($payment));

                        return true;
                    });

                    if ($lookupCompleted === null) {
                        $this->events->checkoutSubmitted(null, $order, [
                            'status_endpoint_result' => 'in_progress',
                        ]);

                        return StorefrontShopSignedStatusRefreshResult::inProgress(
                            $this->coordinator->retryAfterSeconds(),
                        );
                    }

                    $this->coordinator->markRecentlyRefreshed($payment);
                } elseif (! $skipProviderLookup) {
                    $lock = $this->coordinator->acquire($payment);
                    if ($lock === null) {
                        $this->events->checkoutSubmitted(null, $order, [
                            'status_endpoint_result' => 'in_progress',
                        ]);

                        return StorefrontShopSignedStatusRefreshResult::inProgress(
                            $this->coordinator->retryAfterSeconds(),
                        );
                    }

                    ProviderVerificationProfiles::using(ProviderVerificationProfile::Interactive, fn () => $this->processProviderReconciliation($payment));
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
        $qrisReady = $this->isNativeQrisPayable($payment);
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
        if (! $projection->isPendingPayment) {
            return false;
        }

        return ($this->isPakasirPayment($payment) && $payment->method === 'hosted')
            || $this->isNativeQrisPayable($payment);
    }

    private function shouldRecoverQris(OrderLifecycleProjection $projection, Payment $payment): bool
    {
        return $this->hasNativeQrisRail($payment)
            && $projection->isPendingPayment
            && ! $this->isNativeQrisPayable($payment);
    }

    private function isAutomaticQrisPayment(?Payment $payment): bool
    {
        return $this->isPakasirPayment($payment) || $this->isKlikQrisPayment($payment);
    }

    private function isPakasirPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::PAKASIR;
    }

    private function isKlikQrisPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::KLIKQRIS;
    }

    private function hasNativeQrisRail(?Payment $payment): bool
    {
        return $this->isKlikQrisPayment($payment)
            || ($this->isPakasirPayment($payment) && $payment?->method === 'api_qris');
    }

    private function isBinancePayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::MANUAL
            && strtoupper((string) $payment?->method) === 'BINANCE_PAY_ID';
    }

    private function shouldVerifyBinance(OrderLifecycleProjection $projection, BinanceVerificationStatus $verificationStatus): bool
    {
        return $projection->isPendingPayment
            && $verificationStatus->automatic
            && ! $verificationStatus->closed()
            && in_array($verificationStatus->outcome, [
                BinanceVerificationOutcome::NotFound,
                BinanceVerificationOutcome::IncompleteWindow,
                BinanceVerificationOutcome::ProviderError,
            ], true)
            && ($verificationStatus->mode === 'amount_unique' || $verificationStatus->submitted);
    }

    private function hasPayableNativeQris(?Payment $payment): bool
    {
        return $payment?->isPayableNativePakasirQris() === true;
    }

    /**
     * Whether the currently persisted state is payable. KlikQRIS payments are
     * classified by the KlikQRIS lifecycle outcome; Pakasir keeps its legacy
     * model-level readiness check.
     */
    private function isNativeQrisPayable(?Payment $payment): bool
    {
        if ($this->isKlikQrisPayment($payment)) {
            return $this->klikQrisNativeQris->classify($payment)->outcome() === KlikQrisNativeQrisOutcome::Payable;
        }

        return $this->hasPayableNativeQris($payment);
    }

    private function isPayableOutcome(?KlikQrisNativeQrisOutcome $outcome, Payment $payment): bool
    {
        if ($outcome !== null) {
            return $outcome === KlikQrisNativeQrisOutcome::Payable;
        }

        return $this->isNativeQrisPayable($payment);
    }

    private function attemptNativeQrisRecovery(Payment $payment): ?KlikQrisNativeQrisOutcome
    {
        if ($this->isKlikQrisPayment($payment)) {
            return $this->klikQrisNativeQris->lifecycle($payment)->outcome();
        }

        if ($this->isPakasirPayment($payment) && $payment->method === 'api_qris') {
            $this->nativeQris->ensureCreated($payment);
        }

        return null;
    }

    private function processProviderReconciliation(Payment $payment): void
    {
        if ($this->isKlikQrisPayment($payment)) {
            $this->klikQrisProcessor->reconcilePayment($payment);

            return;
        }

        $this->processor->processReconciliationForPayment($payment, $this->pakasir->transactionDetail($payment));
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
    private function qrisReadiness(OrderLifecycleProjection $projection, ?Payment $payment, ?KlikQrisNativeQrisOutcome $outcome = null): array
    {
        if (! $projection->isPendingPayment) {
            return ['state' => 'terminal', 'title' => 'QRIS tidak aktif', 'message' => 'Invoice ini tidak sedang menunggu pembayaran QRIS.'];
        }

        if ($outcome !== null) {
            return match ($outcome) {
                KlikQrisNativeQrisOutcome::Payable => ['state' => 'payable', 'title' => 'QRIS siap dibayar', 'message' => 'QRIS dan total bayar final sudah siap.'],
                KlikQrisNativeQrisOutcome::Creating => ['state' => 'creating', 'title' => 'Sedang membuat QRIS', 'message' => 'Provider sedang menyiapkan QRIS dan nominal final. Jangan bayar sebelum QRIS tampil.'],
                KlikQrisNativeQrisOutcome::RecoveryPending => ['state' => 'recovery_pending', 'title' => 'Sedang membuat QRIS', 'message' => 'QRIS sedang dipulihkan. Buka kembali invoice ini sebentar lagi.'],
                KlikQrisNativeQrisOutcome::ProviderTotalPending => ['state' => 'provider_total_pending', 'title' => 'Provider belum memberi nominal', 'message' => 'QRIS sudah dibuat, tetapi total bayar final dari provider belum tersedia. Jangan bayar total produk.'],
                KlikQrisNativeQrisOutcome::InvalidProviderResult => ['state' => 'failed', 'title' => 'Gagal membuat QRIS', 'message' => 'QRIS belum siap dipakai. Coba buat ulang dari invoice ini atau hubungi bantuan jika tetap gagal.'],
                KlikQrisNativeQrisOutcome::Expired => ['state' => 'expired', 'title' => 'QRIS kedaluwarsa', 'message' => 'Batas bayar QRIS sudah lewat. Jangan bayar QRIS ini; buat order baru dari katalog.'],
            };
        }

        if (filled($payment?->provider_payment_number) && $payment?->provider_total_payment === null) {
            return ['state' => 'provider_total_pending', 'title' => 'Provider belum memberi nominal', 'message' => 'QRIS sudah dibuat, tetapi total bayar final dari provider belum tersedia. Jangan bayar total produk.'];
        }

        if ($this->isPakasirPayment($payment)
            && in_array($payment?->provider_create_state, ['outcome_unknown', 'provider_total_pending', 'financial_integrity_failed'], true)) {
            return ['state' => 'failed', 'title' => 'Gagal membuat QRIS', 'message' => 'QRIS belum siap dipakai. Coba buat ulang dari invoice ini atau hubungi bantuan jika tetap gagal.'];
        }

        return ['state' => 'creating', 'title' => 'Sedang membuat QRIS', 'message' => 'Provider sedang menyiapkan QRIS dan nominal final. Jangan bayar sebelum QRIS tampil.'];
    }
}
