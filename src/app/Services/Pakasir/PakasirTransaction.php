<?php

namespace App\Services\Pakasir;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class PakasirTransaction
{
    public function __construct(private readonly array $payload) {}

    public static function fromCallbackPayload(array $payload): self
    {
        return new self($payload);
    }

    public static function fromPayload(array $payload): ?self
    {
        $transaction = Arr::get($payload, 'transaction', Arr::get($payload, 'data', $payload));

        if (is_array($transaction) && isset($transaction['transaction']) && is_array($transaction['transaction'])) {
            $transaction = $transaction['transaction'];
        }

        return is_array($transaction) ? new self($transaction) : null;
    }

    public function project(): mixed
    {
        return Arr::get($this->payload, 'project');
    }

    public function amount(): int
    {
        return (int) Arr::get($this->payload, 'amount');
    }

    public function orderId(): mixed
    {
        return Arr::get($this->payload, 'order_id');
    }

    public function status(): mixed
    {
        return Arr::get($this->payload, 'status');
    }

    public function paymentMethod(): ?string
    {
        $value = Arr::get($this->payload, 'payment_method');

        return is_string($value) && trim($value) !== '' ? strtolower($value) : null;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = Arr::get($this->payload, 'expired_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($this->normalizeTimestamp($value));
    }

    public function isCompleted(): bool
    {
        return strcasecmp((string) $this->status(), 'completed') === 0;
    }

    private function normalizeTimestamp(string $value): string
    {
        return preg_replace('/\.(\d{6})\d+(Z|[+-]\d{2}:\d{2})$/', '.$1$2', $value) ?? $value;
    }
}
