<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;

class StorefrontShopSignedStatusRefreshResult
{
    private const UNSUPPORTED_PAYMENT_KEY = 'storefront.invoice.status_ui.common.unsupported_message';

    private const RETRYABLE_PROVIDER_FAILURE_KEY = 'storefront.invoice.status_ui.common.retryable_message';

    private const IN_PROGRESS_KEY = 'storefront.invoice.status_ui.common.in_progress_message';

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
        public readonly ?string $status = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $paymentRail = null,
        public readonly ?string $bscStatus = null,
        public readonly ?int $bscConfirmations = null,
        public readonly ?int $bscRequiredConfirmations = null,
    ) {}

    public static function unsupportedPayment(): self
    {
        return new self(
            ok: false,
            statusCode: 422,
            status: 'unsupported_payment',
            message: self::translate(self::UNSUPPORTED_PAYMENT_KEY),
        );
    }

    public static function retryableProviderFailure(?int $retryAfter = null): self
    {
        return new self(
            ok: false,
            statusCode: 200,
            status: 'provider_failure',
            title: self::translate('storefront.invoice.status_ui.common.retryable_title'),
            message: self::translate(self::RETRYABLE_PROVIDER_FAILURE_KEY),
            retryAfter: $retryAfter,
        );
    }

    public static function inProgress(int $retryAfter): self
    {
        return new self(
            ok: false,
            statusCode: 202,
            status: 'in_progress',
            title: self::translate('storefront.invoice.status_ui.common.in_progress_title'),
            message: self::translate(self::IN_PROGRESS_KEY),
            retryAfter: $retryAfter,
        );
    }

    public static function qrisNotReady(string $title, string $message): self
    {
        return new self(
            ok: false,
            statusCode: 200,
            status: 'qris_not_ready',
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
        ?string $paymentRail = null,
        ?string $bscStatus = null,
        ?int $bscConfirmations = null,
        ?int $bscRequiredConfirmations = null,
    ): self {
        return new self(
            ok: true,
            statusCode: 200,
            status: match (true) {
                $isPaid => 'paid',
                $isTerminal => 'terminal',
                default => 'refreshed',
            },
            paymentStatus: $paymentStatus,
            orderStatus: $orderStatus,
            isPaid: $isPaid,
            isTerminal: $isTerminal,
            qrisReady: $qrisReady,
            shouldReload: $shouldReload,
            redirectUrl: $redirectUrl,
            message: $message,
            paymentRail: $paymentRail,
            bscStatus: $bscStatus,
            bscConfirmations: $bscConfirmations,
            bscRequiredConfirmations: $bscRequiredConfirmations,
        );
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, bool|int|string|null>
     */
    public function payload(): array
    {
        if (! $this->ok) {
            return array_filter([
                'ok' => false,
                'status' => $this->status,
                'title' => $this->title,
                'message' => $this->message,
                'retry_after' => $this->retryAfter,
            ], fn ($value): bool => $value !== null);
        }

        $payload = [
            'ok' => true,
            'status' => $this->status,
            'payment_status' => $this->paymentStatus?->value,
            'order_status' => $this->orderStatus?->value,
            'is_paid' => $this->isPaid,
            'is_terminal' => $this->isTerminal,
            'qris_ready' => $this->qrisReady,
            'should_reload' => $this->shouldReload,
            'redirect_url' => $this->redirectUrl,
            'message' => $this->message,
        ];

        if ($this->paymentRail !== null) {
            $payload += [
                'payment_rail' => $this->paymentRail,
                'bsc_status' => $this->bscStatus,
                'bsc_confirmations' => $this->bscConfirmations,
                'bsc_required_confirmations' => $this->bscRequiredConfirmations,
            ];
        }

        return $payload;
    }

    private static function translate(string $key): string
    {
        $locale = strtolower(str_replace('_', '-', trim((string) config('storefront.locale', 'id'))));
        $locale = explode('-', $locale, 2)[0];
        if (! in_array($locale, ['id', 'en', 'ms'], true)) {
            $locale = 'id';
        }

        return (string) __($key, [], $locale);
    }
}
