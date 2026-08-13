<?php

namespace App\Services\KlikQris;

use App\Enums\KlikQrisEnvironment;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Presentation-safe Payable payload: trusted QR media, the provider final
 * total, and the effective deadline (the earlier valid deadline of the local
 * Order and the provider expiry).
 */
final readonly class KlikQrisPayableNativeQris
{
    private const MAX_TRANSIENT_IMAGE_BYTES = 2_000_000;

    private function __construct(
        private string $qrisUrl,
        private int $totalAmount,
        private string $totalAmountDisplay,
        private CarbonImmutable $effectiveExpiresAt,
        private ?string $fallbackImageContents,
    ) {}

    /**
     * Build the Payable payload from a persisted Payment, failing closed on
     * missing, invalid, expired, or untrusted provider data.
     */
    public static function fromPayment(Payment $payment, ?KlikQrisEnvironment $environment = null): self
    {
        return self::fromPaymentWithTransientImage($payment, null, $environment);
    }

    /**
     * Build the Payable payload with an optional provider image held only for
     * the current presentation attempt. The base64 input is decoded and
     * validated before it crosses the presentation boundary and is never
     * persisted.
     */
    public static function fromPaymentWithTransientImage(
        Payment $payment,
        ?string $qrisImageBase64,
        ?KlikQrisEnvironment $environment = null,
    ): self {
        $environment ??= KlikQrisEnvironment::runtime();

        // Full integrity gate: payload columns must match, QRIS media must be
        // trusted, and the payment must be payable or already paid.
        $transaction = KlikQrisTransaction::fromPersistedPayment($payment, $environment);

        $providerExpiresAt = $transaction->expiresAt();
        if ($providerExpiresAt === null) {
            throw new RuntimeException('KlikQRIS payable transaction has no provider expiry.');
        }

        $orderExpiresAt = $payment->order?->expires_at;
        $effectiveExpiresAt = $orderExpiresAt !== null && $orderExpiresAt->lt($providerExpiresAt)
            ? CarbonImmutable::instance($orderExpiresAt)
            : $providerExpiresAt;

        if ($payment->status !== PaymentStatus::Paid && ! $effectiveExpiresAt->isFuture()) {
            throw new RuntimeException('KlikQRIS payable transaction deadline has passed.');
        }

        $display = $payment->klikQrisTotalAmountDisplay();
        if ($display === null) {
            throw new RuntimeException('KlikQRIS payable transaction final total is inconsistent.');
        }

        $totalAmount = (int) $payment->provider_total_payment;
        if ($totalAmount < (int) $payment->amount) {
            throw new RuntimeException('KlikQRIS payable transaction final total is below the order amount.');
        }

        $qrisUrl = (string) $transaction->qrisUrl();
        if ($qrisUrl === '') {
            throw new RuntimeException('KlikQRIS payable transaction has no presentation-safe QR media.');
        }

        return new self(
            $qrisUrl,
            $totalAmount,
            $display,
            $effectiveExpiresAt,
            self::decodeTransientImage($qrisImageBase64),
        );
    }

    public function qrisUrl(): string
    {
        return $this->qrisUrl;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount;
    }

    public function totalAmountDisplay(): string
    {
        return $this->totalAmountDisplay;
    }

    public function effectiveExpiresAt(): CarbonImmutable
    {
        return $this->effectiveExpiresAt;
    }

    public function fallbackImageContents(): ?string
    {
        return $this->fallbackImageContents;
    }

    private static function decodeTransientImage(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/]+=*)$/', $value, $matches)) {
            return null;
        }

        $decoded = base64_decode($matches[1], true);
        if (! is_string($decoded)
            || strlen($decoded) > self::MAX_TRANSIENT_IMAGE_BYTES
            || ! str_starts_with($decoded, "\x89PNG\r\n\x1A\n")) {
            return null;
        }

        return $decoded;
    }
}
