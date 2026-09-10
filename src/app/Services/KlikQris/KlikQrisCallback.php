<?php

namespace App\Services\KlikQris;

use UnexpectedValueException;

final readonly class KlikQrisCallback
{
    private const SUPPORTED_STATUSES = ['PENDING', 'PAID', 'SUCCESS', 'EXPIRED'];

    private const MAX_STATUS_LENGTH = 40;

    /** @param array<mixed> $sanitizedPayload */
    private function __construct(
        private string $orderId,
        private KlikQrisAmount $amount,
        private KlikQrisAmount $totalAmount,
        private string $status,
        private string $signature,
        private array $sanitizedPayload,
    ) {}

    /** @param array<mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        $orderId = self::requiredString($payload, 'order_id', 'KlikQRIS order_id is required.');
        $amount = KlikQrisAmount::from($payload['amount'] ?? null);
        $totalAmount = KlikQrisAmount::from($payload['total_amount'] ?? null);
        $status = strtoupper(self::requiredString($payload, 'status', 'KlikQRIS callback status is required.'));

        if (strlen($status) > self::MAX_STATUS_LENGTH
            || preg_match('/\A[A-Z0-9][A-Z0-9._-]*\z/D', $status) !== 1) {
            throw new UnexpectedValueException('KlikQRIS callback status is invalid.');
        }

        $signature = self::requiredString($payload, 'signature', 'KlikQRIS callback signature is required.');
        $sanitizedPayload = in_array($status, self::SUPPORTED_STATUSES, true)
            ? KlikQrisPayloadSanitizer::sanitize($payload)
            : KlikQrisPayloadSanitizer::unknownCallbackStatus($status, $orderId);

        return new self($orderId, $amount, $totalAmount, $status, $signature, $sanitizedPayload);
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

    public function signature(): string
    {
        return $this->signature;
    }

    public function providerReference(): ?string
    {
        $data = $this->sanitizedPayload['data'] ?? $this->sanitizedPayload;
        if (! is_array($data)) {
            return null;
        }

        foreach (['provider_reference', 'transaction_id', 'transaction_ref', 'reference'] as $key) {
            $value = $data[$key] ?? null;
            if (! is_scalar($value)) {
                continue;
            }

            $reference = trim((string) $value);
            if ($reference !== '') {
                return $reference;
            }
        }

        return null;
    }

    public function isSupportedStatus(): bool
    {
        return in_array($this->status, self::SUPPORTED_STATUSES, true);
    }

    public function isPaidTrigger(): bool
    {
        return in_array($this->status, ['PAID', 'SUCCESS'], true);
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
