<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use App\Models\Payment;
use App\Services\Pakasir\QrisEmvPayload;
use App\Support\PaymentProvider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Presentation-safe Sakurupiah invoice data.
 *
 * The payload contains only validated QRIS or hosted presentation data. It is
 * intentionally impossible to construct it from an unvalidated provider
 * response without passing the payment/reference/amount/expiry gates below.
 */
final readonly class SakurupiahPayableInvoice
{
    private function __construct(
        private string $merchantRef,
        private string $providerReference,
        private string $paymentMethod,
        private int $totalAmount,
        private int $providerFee,
        private CarbonImmutable $effectiveExpiresAt,
        private ?string $qrisPayload,
        private ?string $checkoutUrl,
    ) {}

    public static function fromInvoice(
        Payment $payment,
        SakurupiahInvoice $invoice,
        CarbonInterface $effectiveExpiresAt,
        SakurupiahEnvironment $environment,
    ): self {
        if ($payment->provider !== PaymentProvider::SAKURUPIAH
            || $payment->method !== SakurupiahCreateInvoice::METHOD_QRIS
            || $payment->currency !== 'IDR') {
            throw new SakurupiahInvoiceIntegrityException('payment_identity');
        }

        if ($invoice->merchantRef !== (string) $payment->merchant_ref) {
            throw new SakurupiahInvoiceIntegrityException('merchant_reference');
        }

        if ($invoice->paymentCode !== SakurupiahCreateInvoice::METHOD_QRIS) {
            throw new SakurupiahInvoiceIntegrityException('payment_method');
        }

        if ($invoice->amountValue() !== null && $invoice->amountValue() !== (int) $payment->amount) {
            throw new SakurupiahInvoiceIntegrityException('amount');
        }
        $merchantFee = $invoice->merchantFee();
        $configuredMerchantFee = config('services.sakurupiah.qris_contract.merchant_fee');
        if ($merchantFee !== null
            && (! is_int($configuredMerchantFee) || $merchantFee !== $configuredMerchantFee)) {
            throw new SakurupiahInvoiceIntegrityException('fee_payer');
        }

        $fee = $invoice->fee?->fixedAmount();
        if ($fee === null || $invoice->totalValue() !== (int) $payment->amount + $fee) {
            throw new SakurupiahInvoiceIntegrityException('fee');
        }

        if (! $invoice->isPending()) {
            throw new SakurupiahInvoiceIntegrityException('status');
        }

        if ($invoice->expiresAt === null || ! $invoice->expiresAt->isFuture()) {
            throw new SakurupiahInvoiceIntegrityException('expiry');
        }

        $effectiveExpiresAt = CarbonImmutable::instance($effectiveExpiresAt);
        if (! $effectiveExpiresAt->isFuture()) {
            throw new SakurupiahInvoiceIntegrityException('effective_expiry');
        }

        $qrisPayload = $invoice->qrPayload();
        $checkoutUrl = null;
        if ($qrisPayload !== null) {
            try {
                QrisEmvPayload::parse($qrisPayload)->assertMatchesIdrAmount($invoice->totalValue());
            } catch (\Throwable $exception) {
                throw new SakurupiahInvoiceIntegrityException('qris_presentation', previous: $exception);
            }
        } else {
            $checkoutUrl = $invoice->checkoutUrl();
            if ($checkoutUrl === null || ! self::isAllowedHostedUrl($checkoutUrl, $environment)) {
                throw new SakurupiahInvoiceIntegrityException('hosted_presentation');
            }
        }

        return new self(
            merchantRef: (string) $payment->merchant_ref,
            providerReference: $invoice->trxId,
            paymentMethod: $invoice->paymentCode,
            totalAmount: $invoice->totalValue(),
            providerFee: $fee,
            effectiveExpiresAt: $effectiveExpiresAt,
            qrisPayload: $qrisPayload,
            checkoutUrl: $checkoutUrl,
        );
    }

    public function merchantRef(): string
    {
        return $this->merchantRef;
    }

    public function providerReference(): string
    {
        return $this->providerReference;
    }

    public function paymentMethod(): string
    {
        return $this->paymentMethod;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function providerFee(): int
    {
        return $this->providerFee;
    }

    public function effectiveExpiresAt(): CarbonImmutable
    {
        return $this->effectiveExpiresAt;
    }

    public function qrisPayload(): ?string
    {
        return $this->qrisPayload;
    }

    public function checkoutUrl(): ?string
    {
        return $this->checkoutUrl;
    }

    public function isDirectQris(): bool
    {
        return $this->qrisPayload !== null;
    }

    public function isHosted(): bool
    {
        return $this->checkoutUrl !== null;
    }

    private static function isAllowedHostedUrl(string $url, SakurupiahEnvironment $environment): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('port', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
        ) {
            return false;
        }

        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        $configuredHosts = config($environment->configKey('allowed_hosts'), []);
        if (is_string($configuredHosts)) {
            $configuredHosts = explode(',', $configuredHosts);
        }

        $allowedHosts = array_values(array_filter(array_map(
            static fn (mixed $candidate): ?string => is_string($candidate)
                ? strtolower(rtrim(trim($candidate), '.'))
                : null,
            (array) $configuredHosts,
        ), static fn (mixed $candidate): bool => is_string($candidate) && $candidate !== ''));

        return in_array($host, $allowedHosts, true);
    }
}
