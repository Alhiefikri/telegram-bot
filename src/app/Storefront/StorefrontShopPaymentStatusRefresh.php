<?php

namespace App\Storefront;

use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\Bsc\BscVerificationStatus;
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
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleResult;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
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
        private readonly SakurupiahInvoiceLifecycleService $sakurupiahLifecycle,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly StorefrontFunnelEvents $events,
        private readonly OrderLifecycle $orders,
        private readonly StorefrontShopPaymentRefreshCoordinator $coordinator,
        private readonly BinancePaymentVerification $verification,
        private readonly BscPaymentVerification $bscVerification,
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

        if ($this->isBscPayment($payment)) {
            $order->refresh();
            $payment->loadMissing('bscDetail');
            $status = $this->bscVerification->status($payment);
            if ($this->orders->project($order, $payment)->isPendingPayment) {
                $queued = false;
                try {
                    $queued = $this->bscVerification->queueForStatusRefresh($payment);
                } catch (Throwable $exception) {
                    report($exception);
                    $status = $status->outcome === BscVerificationOutcome::ProviderError
                        ? $status
                        : new BscVerificationStatus(
                            outcome: BscVerificationOutcome::ProviderError,
                            status: 'provider_error',
                            transactionHash: $status->transactionHash,
                            confirmations: $status->confirmations,
                            attempts: $status->attempts,
                            version: $status->version,
                            requiredConfirmations: $status->requiredConfirmations,
                        );
                }

                if ($queued) {
                    // RPC reads belong to the queue. Refresh after dispatch so
                    // a synchronous worker still reports persisted state and
                    // never treats browser-provided evidence as authoritative.
                    $order->refresh();
                    $payment->refresh();
                    $payment->loadMissing('bscDetail');
                    $status = $this->bscVerification->status($payment);
                }
            }
            $projection = $this->orders->project($order, $payment);
            $required = $status->requiredConfirmations;

            return StorefrontShopSignedStatusRefreshResult::refreshed(
                paymentStatus: $payment->status,
                orderStatus: $order->order_status,
                isPaid: $projection->isPaid,
                isTerminal: $projection->isTerminal,
                qrisReady: false,
                shouldReload: $projection->isPaid,
                redirectUrl: $this->invoiceUrls->show($order),
                message: $this->terminalCopy->bscRefreshStatusMessage($status->outcome, $status->confirmations, $required),
                paymentRail: 'usdt_bep20',
                bscStatus: $status->status,
                bscConfirmations: $status->confirmations,
                bscRequiredConfirmations: $required,
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
                && ($this->isKlikQrisPayment($payment) || $this->isSakurupiahPayment($payment))
                && $recoveryOutcome !== null
                && ! in_array($recoveryOutcome, ['payable', 'provider_total_pending'], true)) {
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
            $skipProviderLookup = $qrisRecoveredDuringRetry
                || $this->coordinator->recentlyRefreshed($payment);
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
                } elseif (! $skipProviderLookup && $this->isSakurupiahPayment($payment)) {
                    $lookupResult = ProviderVerificationProfiles::using(
                        ProviderVerificationProfile::Interactive,
                        fn (): ?SakurupiahInvoiceLifecycleResult => $this->sakurupiahLifecycle->tryRefreshStatus($payment),
                    );

                    if ($lookupResult === null) {
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
            message: $shouldReload
                ? $this->terminalCopy->translate('storefront.invoice.status_ui.common.qris_ready_message')
                : $this->statusMessage($order, $payment),
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
        return $this->isPakasirPayment($payment)
            || $this->isKlikQrisPayment($payment)
            || $this->isSakurupiahPayment($payment);
    }

    private function isPakasirPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::PAKASIR;
    }

    private function isKlikQrisPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::KLIKQRIS;
    }

    private function isSakurupiahPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::SAKURUPIAH;
    }

    private function hasNativeQrisRail(?Payment $payment): bool
    {
        return $this->isSakurupiahPayment($payment)
            || $this->isKlikQrisPayment($payment)
            || ($this->isPakasirPayment($payment) && $payment?->method === 'api_qris');
    }

    private function isBinancePayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::MANUAL
            && strtoupper((string) $payment?->method) === 'BINANCE_PAY_ID';
    }

    private function isBscPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === PaymentProvider::MANUAL
            && strtoupper((string) $payment?->method) === 'USDT_BEP20';
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
        if ($this->isSakurupiahPayment($payment)) {
            return $this->sakurupiahLifecycle->classify($payment)->isPayable();
        }

        return $payment?->isPayableNativePakasirQris() === true;
    }

    /**
     * Whether the currently persisted state is payable. KlikQRIS and
     * Sakurupiah payments are classified by their lifecycle; Pakasir keeps its
     * legacy model-level readiness check.
     */
    private function isNativeQrisPayable(?Payment $payment): bool
    {
        if ($this->isKlikQrisPayment($payment)) {
            return $this->klikQrisNativeQris->classify($payment)->outcome() === KlikQrisNativeQrisOutcome::Payable;
        }

        return $this->hasPayableNativeQris($payment);
    }

    private function isPayableOutcome(?string $outcome, Payment $payment): bool
    {
        if ($outcome !== null) {
            return $outcome === 'payable';
        }

        return $this->isNativeQrisPayable($payment);
    }

    private function attemptNativeQrisRecovery(Payment $payment): ?string
    {
        if ($this->isKlikQrisPayment($payment)) {
            return $this->klikQrisNativeQris->lifecycle($payment)->outcome()->value;
        }

        if ($this->isSakurupiahPayment($payment)) {
            return $this->sakurupiahLifecycle->lifecycle($payment)->outcome()->value;
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
    private function qrisReadiness(OrderLifecycleProjection $projection, ?Payment $payment, ?string $outcome = null): array
    {
        if (! $projection->isPendingPayment) {
            $copy = $this->terminalCopy->qrisReadiness('terminal');

            return [
                'state' => $copy['state'],
                'title' => $copy['title'],
                'message' => $copy['message'],
            ];
        }

        if ($outcome !== null) {
            $state = match ($outcome) {
                'payable' => 'ready',
                'creating' => 'creating',
                'recovery_pending' => 'recovery_pending',
                'provider_total_pending' => 'provider_total_pending',
                'invalid_provider_result' => 'failed',
                'expired' => 'expired',
                default => 'failed',
            };
            $copy = $this->terminalCopy->qrisReadiness($state);

            return [
                'state' => $copy['state'],
                'title' => $copy['title'],
                'message' => $copy['message'],
            ];
        }

        if ($this->isPakasirPayment($payment)
            && in_array($payment?->provider_create_state, ['outcome_unknown', 'provider_total_pending', 'financial_integrity_failed'], true)) {
            $copy = $this->terminalCopy->qrisReadiness('failed');

            return [
                'state' => $copy['state'],
                'title' => $copy['title'],
                'message' => $copy['message'],
            ];
        }

        $copy = $this->terminalCopy->qrisReadiness(
            filled($payment?->provider_payment_number) && $payment?->provider_total_payment === null
                ? 'provider_total_pending'
                : 'creating',
        );

        return [
            'state' => $copy['state'],
            'title' => $copy['title'],
            'message' => $copy['message'],
        ];
    }
}
