<?php

namespace App\Services\Pakasir;

use App\Models\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use UnexpectedValueException;

readonly class PakasirQrisTransaction
{
    private array $payment;

    public function __construct(private array $payload)
    {
        $payment = Arr::get($payload, 'payment');

        if (! is_array($payment)) {
            throw new UnexpectedValueException('Pakasir QRIS create response is missing payment data.');
        }

        $this->payment = $payment;
        $this->stringField('project');
        $this->stringField('order_id');
        $this->intField('amount');
        $this->stringField('payment_method');
        $this->stringField('payment_number');
    }

    public static function fromPayload(array $payload): self
    {
        return new self($payload);
    }

    public function project(): string
    {
        return $this->stringField('project');
    }

    public function orderId(): string
    {
        return $this->stringField('order_id');
    }

    public function amount(): int
    {
        return $this->intField('amount');
    }

    public function fee(): ?int
    {
        return $this->nullableIntField('fee');
    }

    public function totalPayment(): ?int
    {
        return $this->nullableIntField('total_payment');
    }

    public function paymentMethod(): string
    {
        return $this->stringField('payment_method');
    }

    public function paymentNumber(): string
    {
        return $this->stringField('payment_number');
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $value = Arr::get($this->payment, 'expired_at');

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($this->normalizeTimestamp($value));
    }

    public function assertMatches(Payment $payment, string $project): void
    {
        if ($this->project() !== $project) {
            throw new UnexpectedValueException('Pakasir QRIS create response project mismatch.');
        }

        if ($this->orderId() !== $payment->merchant_ref) {
            throw new UnexpectedValueException('Pakasir QRIS create response order mismatch.');
        }

        if ($this->amount() !== $payment->amount) {
            throw new UnexpectedValueException('Pakasir QRIS create response amount mismatch.');
        }

        if ($this->paymentMethod() !== 'qris') {
            throw new UnexpectedValueException('Pakasir QRIS create response method mismatch.');
        }
    }

    public function sanitizedPayload(): array
    {
        return $this->sanitize($this->payload);
    }

    private function stringField(string $key): string
    {
        $value = Arr::get($this->payment, $key);

        if (! is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Pakasir QRIS create response is missing {$key}.");
        }

        return $value;
    }

    private function intField(string $key): int
    {
        $value = Arr::get($this->payment, $key);

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new UnexpectedValueException("Pakasir QRIS create response is missing {$key}.");
        }

        return (int) $value;
    }

    private function nullableIntField(string $key): ?int
    {
        $value = Arr::get($this->payment, $key);

        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new UnexpectedValueException("Pakasir QRIS create response has invalid {$key}.");
        }

        return (int) $value;
    }

    private function normalizeTimestamp(string $value): string
    {
        return preg_replace('/\.(\d{6})\d+(Z|[+-]\d{2}:\d{2})$/', '.$1$2', $value) ?? $value;
    }

    private function sanitize(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));

        return in_array($normalized, ['paymentnumber', 'apikey', 'secret', 'token', 'password', 'authorization'], true);
    }
}
