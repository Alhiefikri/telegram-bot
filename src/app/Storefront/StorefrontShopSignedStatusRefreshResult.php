<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

class StorefrontShopSignedStatusRefreshResult
{
    private const PAKASIR_NOT_FOUND = 'Pembayaran QRIS tidak ditemukan.';

    private const RETRYABLE_PROVIDER_FAILURE = 'Status pembayaran belum bisa dicek sekarang. Coba lagi beberapa saat lagi.';

    private function __construct(
        public readonly bool $ok,
        private readonly int $statusCode,
        public readonly ?PaymentStatus $paymentStatus = null,
        public readonly ?OrderStatus $orderStatus = null,
        public readonly bool $isPaid = false,
        public readonly bool $isTerminal = false,
        public readonly bool $qrisReady = false,
        public readonly bool $shouldReload = false,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $title = null,
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
            title: 'Status belum bisa dicek',
            message: self::RETRYABLE_PROVIDER_FAILURE,
        );
    }

    public static function qrisNotReady(string $title, string $message): self
    {
        return new self(
            ok: false,
            statusCode: 200,
            title: $title,
            message: $message,
        );
    }

    public static function refreshed(
        ?PaymentStatus $paymentStatus,
        ?OrderStatus $orderStatus,
        bool $isPaid,
        bool $isTerminal,
        bool $qrisReady,
        bool $shouldReload,
        string $redirectUrl,
        ?string $message = null,
    ): self {
        return new self(
            ok: true,
            statusCode: 200,
            paymentStatus: $paymentStatus,
            orderStatus: $orderStatus,
            isPaid: $isPaid,
            isTerminal: $isTerminal,
            qrisReady: $qrisReady,
            shouldReload: $shouldReload,
            redirectUrl: $redirectUrl,
            message: $message,
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
            return array_filter([
                'ok' => false,
                'title' => $this->title,
                'message' => $this->message,
            ], fn ($value): bool => $value !== null);
        }

        return [
            'ok' => true,
            'payment_status' => $this->paymentStatus?->value,
            'order_status' => $this->orderStatus?->value,
            'is_paid' => $this->isPaid,
            'is_terminal' => $this->isTerminal,
            'qris_ready' => $this->qrisReady,
            'should_reload' => $this->shouldReload,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
        ];
    }
}
