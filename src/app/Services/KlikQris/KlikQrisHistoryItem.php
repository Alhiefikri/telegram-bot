<?php

namespace App\Services\KlikQris;

use Carbon\CarbonImmutable;
use UnexpectedValueException;

final readonly class KlikQrisHistoryItem
{
    private function __construct(
        private string $orderId,
        private CarbonImmutable $createdAt,
        private KlikQrisAmount $totalAmount,
        private string $status,
        private ?string $type,
        private ?string $keterangan,
        private ?string $via,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        return new self(
            orderId: self::requiredString($payload, 'order_id'),
            createdAt: KlikQrisTimestamp::parse($payload['created_at'] ?? null),
            totalAmount: KlikQrisAmount::from($payload['total_amount'] ?? null),
            status: self::requiredStatus($payload, 'status'),
            type: self::optionalString($payload['type'] ?? null),
            keterangan: self::optionalString($payload['keterangan'] ?? null),
            via: self::optionalString($payload['via'] ?? null),
        );
    }

    public function orderId(): string
    {
        return $this->orderId;
    }

    public function createdAt(): CarbonImmutable
    {
        return $this->createdAt;
    }

    public function totalAmount(): int
    {
        return $this->totalAmount->rupiah();
    }

    public function totalAmountDisplay(): string
    {
        return $this->totalAmount->display();
    }

    public function status(): string
    {
        return $this->status;
    }

    public function type(): ?string
    {
        return $this->type;
    }

    public function keterangan(): ?string
    {
        return $this->keterangan;
    }

    public function via(): ?string
    {
        return $this->via;
    }

    /** @param array<mixed> $payload */
    private static function requiredString(array $payload, string $key): string
    {
        $value = self::optionalString($payload[$key] ?? null);
        if ($value === null) {
            throw new UnexpectedValueException('KlikQRIS history field is missing.');
        }

        return $value;
    }

    /** @param array<mixed> $payload */
    private static function requiredStatus(array $payload, string $key): string
    {
        $value = self::requiredString($payload, $key);
        $status = strtoupper($value);
        if ($status === '') {
            throw new UnexpectedValueException('KlikQRIS history status is missing.');
        }

        return $status;
    }

    private static function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new UnexpectedValueException('KlikQRIS history field is invalid.');
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
