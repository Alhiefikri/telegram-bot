<?php

namespace App\Services\KlikQris;

use UnexpectedValueException;

final readonly class KlikQrisCallback
{
    private const STATUSES = ['PENDING', 'PAID', 'EXPIRED'];

    /** @param array<mixed> $sanitizedPayload */
    private function __construct(
        private string $orderId,
        private KlikQrisAmount $amount,
        private KlikQrisAmount $totalAmount,
        private string $status,
        private array $sanitizedPayload,
    ) {}

    /** @param array<mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $orderId = self::requiredString($payload, 'order_id', 'KlikQRIS order_id is required.');
        $amount = KlikQrisAmount::from($payload['amount'] ?? null);
        $totalAmount = KlikQrisAmount::from($payload['total_amount'] ?? null);
        $status = strtoupper(self::requiredString($payload, 'status', 'KlikQRIS callback status is required.'));

        if (! in_array($status, self::STATUSES, true)) {
            throw new UnexpectedValueException('KlikQRIS callback status is unsupported.');
        }

        return new self($orderId, $amount, $totalAmount, $status, KlikQrisPayloadSanitizer::sanitize($payload));
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function amount(): KlikQrisAmount
    {
        return $this->amount;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount->rupiah();
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isPaidTrigger(): bool
    {
        return $this->status === 'PAID';
    }

    /** @return array<mixed> */
    public function sanitizedPayload(): array
    {
        return $this->sanitizedPayload;
    }

    /** @param array<mixed> $data */
    private static function requiredString(array $data, string $key, string $message): string
    {
        $value = $data[$key] ?? null;
        if (! is_scalar($value)) {
            throw new UnexpectedValueException($message);
        }

        $string = trim((string) $value);
        if ($string === '') {
            throw new UnexpectedValueException($message);
        }

        return $string;
    }
}
