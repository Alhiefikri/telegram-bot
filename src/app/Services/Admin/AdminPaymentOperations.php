<?php

namespace App\Services\Admin;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahPaymentEventLogger;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\WijayaPay\WijayaPayCallbackProcessor;
use App\Services\WijayaPay\WijayaPayInvoiceLifecycleService;
use App\Services\WijayaPay\WijayaPayPaymentEventLogger;
use App\Services\WijayaPay\WijayaPayProviderReadiness;
use App\Support\PaymentProvider;
use InvalidArgumentException;

class AdminPaymentOperations
{
    private const RECONCILABLE_PAYMENT_STATUSES = [
        PaymentStatus::Pending,
        PaymentStatus::Expired,
        PaymentStatus::Failed,
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PakasirProviderAdapter $pakasirClient,
        private readonly PakasirCallbackProcessor $pakasirProcessor,
        private readonly PakasirProviderReadiness $pakasirReadiness,
        private readonly KlikQrisProviderAdapter $klikQrisClient,
        private readonly KlikQrisCallbackProcessor $klikQrisProcessor,
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly SakurupiahCallbackProcessor $sakurupiahProcessor,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly SakurupiahPaymentEventLogger $sakurupiahEvents,
        private readonly WijayaPayCallbackProcessor $wijayapayProcessor,
        private readonly WijayaPayProviderReadiness $wijayapayReadiness,
        private readonly WijayaPayPaymentEventLogger $wijayapayEvents,
    ) {}

    public function canReconcile(Payment $payment): bool
    {
        if (! in_array($payment->status, self::RECONCILABLE_PAYMENT_STATUSES, true)
            || blank($payment->merchant_ref)) {
            return false;
        }

        return match ($payment->provider) {
            PaymentProvider::PAKASIR => $this->pakasirReadiness->verification()->isReady(),
            PaymentProvider::KLIKQRIS => $this->klikQrisReadiness->checkout()->isReady(),
            PaymentProvider::SAKURUPIAH => $this->sakurupiahCanReconcile($payment),
            PaymentProvider::WIJAYAPAY => $this->wijayapayCanReconcile($payment),
            default => false,
        };
    }

    public function reconcile(Payment $payment, ?User $user = null, ?string $ip = null): PaymentEvent
    {
        if (! $this->canReconcile($payment)) {
            throw new InvalidArgumentException('Only supported payment providers with provider identifiers can be reconciled.');
        }

        $event = match ($payment->provider) {
            PaymentProvider::PAKASIR => $this->pakasirProcessor->processReconciliationForPayment($payment, $this->pakasirClient->transactionDetail($payment)),
            PaymentProvider::KLIKQRIS => $this->klikQrisProcessor->processReconciliationForPayment($payment, $this->klikQrisClient->transactionStatus($payment)),
            PaymentProvider::SAKURUPIAH => $this->reconcileSakurupiah($payment),
            PaymentProvider::WIJAYAPAY => $this->reconcileWijayaPay($payment),
            default => throw new InvalidArgumentException('Only supported payment providers with provider identifiers can be reconciled.'),
        };
        $this->audit->log('payment.manually_reconciled', $payment, null, ['payment_event_id' => $event->id], $user, $ip);

        return $event;
    }

    private function sakurupiahCanReconcile(Payment $payment): bool
    {
        return filled($payment->provider_reference)
            && $this->sakurupiahReadiness->configurationReport()->isReady();
    }

    private function reconcileSakurupiah(Payment $payment): PaymentEvent
    {
        $outcome = $this->sakurupiahProcessor->reconcilePayment($payment);
        $latest = $payment->paymentEvents()
            ->where('provider', PaymentProvider::SAKURUPIAH)
            ->where('event_type', PaymentEventType::Reconciliation->value)
            ->latest('received_at')
            ->first();

        if ($latest !== null) {
            return $latest;
        }

        // A busy Payment produces no observation. Record a safe manual
        // reconciliation attempt so the admin action still has an audit trail.
        return $this->sakurupiahEvents->logRecoveryObservation(
            $payment,
            SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
            'manual_reconcile:'.$outcome->value,
        );
    }

    private function wijayapayCanReconcile(Payment $payment): bool
    {
        return filled($payment->merchant_ref)
            && $this->wijayapayReadiness->configurationReport()->isReady();
    }

    private function reconcileWijayaPay(Payment $payment): PaymentEvent
    {
        $outcome = $this->wijayapayProcessor->reconcilePayment($payment);
        $latest = $payment->paymentEvents()
            ->where('provider', PaymentProvider::WIJAYAPAY)
            ->where('event_type', PaymentEventType::Reconciliation->value)
            ->latest('received_at')
            ->first();

        if ($latest !== null) {
            return $latest;
        }

        return $this->wijayapayEvents->logRecoveryObservation(
            $payment,
            WijayaPayInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
            'manual_reconcile:'.$outcome->value,
        );
    }
}
