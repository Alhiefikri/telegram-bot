<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

class StorefrontShopSignedStatusRefreshResult
{
    private const PAKASIR_NOT_FOUND = 'Pembayaran QRIS tidak ditemukan.';

    private const RETRYABLE_PROVIDER_FAILURE = 'Status pembayaran belum bisa dicek otomatis sekarang.';

    private function __construct(
        public readonly bool $ok,
        private readonly int $statusCode,
        public readonly ?PaymentStatus $paymentStatus = null,
        public readonly ?OrderStatus $orderStatus = null,
        public readonly bool $isPaid = false,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $message = null,
    ) {}

    public static function unsupportedPayment(): self
    {
        return new self(
            ok: false,
            statusCode: 422,
            message: self::PAKASIR_NOT_FOUND,
        );
    }

    public static function retryableProviderFailure(): self
    {
        return new self(
            ok: false,
            statusCode: 200,
            message: self::RETRYABLE_PROVIDER_FAILURE,
        );
    }

    public static function refreshed(
        ?PaymentStatus $paymentStatus,
        ?OrderStatus $orderStatus,
        bool $isPaid,
        string $redirectUrl,
    ): self {
        return new self(
            ok: true,
            statusCode: 200,
            paymentStatus: $paymentStatus,
            orderStatus: $orderStatus,
            isPaid: $isPaid,
            redirectUrl: $redirectUrl,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, bool|string|null>
     */
    public function payload(): array
    {
        if (! $this->ok) {
            return [
                'ok' => false,
                'message' => $this->message,
            ];
        }

        return [
            'ok' => true,
            'payment_status' => $this->paymentStatus?->value,
            'order_status' => $this->orderStatus?->value,
            'is_paid' => $this->isPaid,
            'redirect_url' => $this->redirectUrl,
        ];
    }
}
