<?php

namespace App\Services\WijayaPay;

use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Payment;
use App\Models\StoreBalanceTopUp;
use App\Services\Pakasir\QrisEmvPayload;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * One safe, provider-issued presentation for the payable amount.
 *
 * A `qr_string` is usable only when it is a valid EMVCo payload whose tag 54
 * amount equals the provider total. A `qr_image` is usable only when it is an
 * HTTPS URL on the exact allowlisted provider host. Raw provider payloads are
 * never consulted after the encrypted snapshot is written.
 */
final readonly class WijayaPayPayableInvoice
{
    private function __construct(
        public string $merchantRef,
        public string $providerReference,
        public string $paymentMethod,
        public int $totalAmount,
        public int $providerFee,
        public bool $customerPaysFee,
        public CarbonImmutable $effectiveExpiresAt,
        public ?string $qrisPayload,
        public ?string $qrisImageUrl,
    ) {}

    public static function fromInvoice(
        Payment $payment,
        WijayaPayInvoice $invoice,
        CarbonInterface $effectiveExpiresAt,
    ): self {
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::WIJAYAPAY
            || strtoupper(trim((string) $payment->method)) !== WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS
            || strtoupper(trim((string) $payment->currency)) !== 'IDR'
        ) {
            throw new WijayaPaySchemaException('payment_identity');
        }

        if ($invoice->refId !== (string) $payment->merchant_ref) {
            throw new WijayaPaySchemaException('merchant_reference');
        }

        if (! $invoice->isPending()) {
            throw new WijayaPaySchemaException('status');
        }

        if ($invoice->expiresAt === null || ! $invoice->expiresAt->isFuture()) {
            throw new WijayaPaySchemaException('expiry');
        }

        $effectiveExpiresAt = CarbonImmutable::instance($effectiveExpiresAt);
        if (! $effectiveExpiresAt->isFuture()) {
            throw new WijayaPaySchemaException('effective_expiry');
        }

        [$qrisPayload, $qrisImageUrl] = self::resolvePresentation(
            $invoice->qrPayload(),
            $invoice->qrImageUrl(),
            $invoice->totalValue(),
        );

        return new self(
            merchantRef: $invoice->refId,
            providerReference: $invoice->trxReference,
            paymentMethod: $invoice->paymentMethod,
            totalAmount: $invoice->totalValue(),
            providerFee: $invoice->totalFee,
            customerPaysFee: $invoice->customerPaysFee(),
            effectiveExpiresAt: $effectiveExpiresAt,
            qrisPayload: $qrisPayload,
            qrisImageUrl: $qrisImageUrl,
        );
    }

    /**
     * Rebuild the safe presentation from the encrypted Payment snapshot.
     * `provider_payload` is deliberately not consulted.
     */
    public static function fromPersistedPayment(
        Payment $payment,
        ?CarbonInterface $observedExpiresAt = null,
    ): self {
        if (strtolower(trim((string) $payment->provider)) !== PaymentProvider::WIJAYAPAY
            || strtoupper(trim((string) $payment->method)) !== WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS
            || strtoupper(trim((string) $payment->currency)) !== 'IDR'
            || $payment->provider_create_state !== 'created'
            || $payment->status !== PaymentStatus::Pending
        ) {
            throw new WijayaPaySchemaException('payment_identity');
        }

        $merchantRef = trim((string) $payment->merchant_ref);
        $providerReference = trim((string) $payment->provider_reference);
        $fee = $payment->provider_fee;
        $total = $payment->provider_total_payment;
        if ($merchantRef === ''
            || $providerReference === ''
            || ! is_int($fee)
            || $fee < 0
            || ! is_int($total)
            || $total < (int) $payment->amount
            || $fee > $total
            || (int) $payment->amount <= 0
        ) {
            throw new WijayaPaySchemaException('payment_identity');
        }

        $snapshot = $payment->provider_presentation;
        if (! is_array($snapshot)) {
            throw new WijayaPaySchemaException('presentation_snapshot');
        }

        try {
            $effectiveExpiresAt = CarbonImmutable::parse((string) ($snapshot['effective_expires_at'] ?? ''));
        } catch (\Throwable $exception) {
            throw new WijayaPaySchemaException('effective_expiry', previous: $exception);
        }

        if ($observedExpiresAt !== null) {
            $observedExpiresAt = CarbonImmutable::instance($observedExpiresAt);
            if ($observedExpiresAt->lt($effectiveExpiresAt)) {
                $effectiveExpiresAt = $observedExpiresAt;
            }
        }

        if (! $effectiveExpiresAt->isFuture()) {
            throw new WijayaPaySchemaException('effective_expiry');
        }

        foreach ([$payment->provider_expires_at, $payment->expired_at] as $deadline) {
            if ($deadline !== null && $effectiveExpiresAt->gt($deadline)) {
                throw new WijayaPaySchemaException('effective_expiry');
            }
        }

        $mode = $snapshot['mode'] ?? null;
        $qrisPayload = $snapshot['qris_payload'] ?? null;
        $qrisImageUrl = $snapshot['qris_image_url'] ?? null;

        if ($mode === 'qris') {
            if (! is_string($qrisPayload)
                || ! self::isValidQrisPayload($qrisPayload, $total)
                || $qrisImageUrl !== null
            ) {
                throw new WijayaPaySchemaException('qris_presentation');
            }
        } elseif ($mode === 'qris_image') {
            if (! is_string($qrisImageUrl)
                || $qrisImageUrl === ''
                || $qrisPayload !== null
                || ! WijayaPayProviderUrl::isAllowed($qrisImageUrl)
            ) {
                throw new WijayaPaySchemaException('qris_image_presentation');
            }
        } else {
            throw new WijayaPaySchemaException('presentation_snapshot');
        }

        return new self(
            merchantRef: $merchantRef,
            providerReference: $providerReference,
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            totalAmount: $total,
            providerFee: $fee,
            customerPaysFee: (bool) ($snapshot['customer_pays_fee'] ?? false),
            effectiveExpiresAt: $effectiveExpiresAt,
            qrisPayload: $mode === 'qris' ? $qrisPayload : null,
            qrisImageUrl: $mode === 'qris_image' ? $qrisImageUrl : null,
        );
    }

    /**
     * Rebuild a payable presentation from the encrypted Store Balance top-up
     * snapshot. Raw provider payloads are never consulted.
     */
    public static function fromPersistedTopUp(StoreBalanceTopUp $topUp): self
    {
        if (strtolower(trim((string) $topUp->provider)) !== PaymentProvider::WIJAYAPAY
            || strtoupper(trim((string) $topUp->currency)) !== 'IDR'
            || $topUp->status !== StoreBalanceTopUpStatus::Payable
            || $topUp->provider_create_state !== 'created'
        ) {
            throw new WijayaPaySchemaException('payment_identity');
        }

        $merchantRef = trim((string) $topUp->merchant_ref);
        $providerReference = trim((string) $topUp->provider_reference);
        $total = $topUp->provider_total_payment;
        $fee = $topUp->provider_fee;
        $amount = (int) $topUp->amount;
        if ($merchantRef === ''
            || $providerReference === ''
            || ! is_int($total)
            || ! is_int($fee)
            || $total < $amount
            || $fee < 0
            || $fee > $total
            || $amount < 1
        ) {
            throw new WijayaPaySchemaException('payment_identity');
        }

        $expiresAt = $topUp->provider_expires_at ?? $topUp->expires_at;
        if ($expiresAt === null || ! $expiresAt->isFuture()) {
            throw new WijayaPaySchemaException('expiry');
        }

        $qrisPayload = $topUp->provider_qris_payload;
        $qrisImageUrl = $topUp->provider_qris_url;
        $mode = null;
        if (is_string($qrisPayload) && self::isValidQrisPayload($qrisPayload, $total)) {
            $mode = 'qris';
            $qrisImageUrl = null;
        } elseif (is_string($qrisImageUrl) && WijayaPayProviderUrl::isAllowed($qrisImageUrl)) {
            $mode = 'qris_image';
            $qrisPayload = null;
        }

        if ($mode === null) {
            throw new WijayaPaySchemaException('qris_presentation');
        }

        return new self(
            merchantRef: $merchantRef,
            providerReference: $providerReference,
            paymentMethod: WijayaPayCreateInvoice::PAYMENT_METHOD_QRIS,
            totalAmount: $total,
            providerFee: $fee,
            customerPaysFee: $total > $amount,
            effectiveExpiresAt: CarbonImmutable::instance($expiresAt),
            qrisPayload: $mode === 'qris' ? $qrisPayload : null,
            qrisImageUrl: $mode === 'qris_image' ? $qrisImageUrl : null,
        );
    }

    public function totalAmountDisplay(): string
    {
        return 'Rp'.number_format($this->totalAmount, 0, ',', '.');
    }

    public function isDirectQris(): bool
    {
        return $this->qrisPayload !== null;
    }

    public function isQrisImage(): bool
    {
        return $this->qrisImageUrl !== null;
    }

    /** @return array{mode: 'qris'|'qris_image', qris_payload: ?string, qris_image_url: ?string, customer_pays_fee: bool, effective_expires_at: string} */
    public function persistenceSnapshot(): array
    {
        return [
            'mode' => $this->isDirectQris() ? 'qris' : 'qris_image',
            'qris_payload' => $this->qrisPayload,
            'qris_image_url' => $this->qrisImageUrl,
            'customer_pays_fee' => $this->customerPaysFee,
            'effective_expires_at' => $this->effectiveExpiresAt->toIso8601String(),
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    public static function resolvePresentation(?string $qrString, ?string $qrImageUrl, int $total): array
    {
        $payload = $qrString !== null && self::isValidQrisPayload($qrString, $total) ? $qrString : null;
        if ($payload !== null) {
            return [$payload, null];
        }

        if ($qrImageUrl !== null && WijayaPayProviderUrl::isAllowed($qrImageUrl)) {
            return [null, $qrImageUrl];
        }

        throw new WijayaPaySchemaException('qris_presentation');
    }

    public static function isValidQrisPayload(string $payload, int $total): bool
    {
        try {
            QrisEmvPayload::parse($payload)->assertMatchesIdrAmount($total);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
