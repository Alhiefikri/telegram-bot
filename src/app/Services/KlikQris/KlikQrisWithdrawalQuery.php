<?php

namespace App\Services\KlikQris;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class KlikQrisWithdrawalQuery
{
    private const DEFAULT_MAX_LIMIT = 100;

    private const MAX_CONFIGURED_LIMIT = 1000;

    private ?string $status;

    private ?string $orderId;

    private ?string $startDate;

    private ?string $endDate;

    private ?int $limit;

    private int $page;

    public function __construct(
        ?string $status = null,
        ?string $orderId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?int $limit = null,
        int $page = 1,
    ) {
        try {
            $this->status = self::normalizeStatus($status);
            $this->orderId = self::optionalText($orderId);
            $this->startDate = self::normalizeDate($startDate);
            $this->endDate = self::normalizeDate($endDate);
            $this->limit = self::normalizeLimit($limit);
            $this->page = $page;

            if ($this->page < 1) {
                throw new InvalidArgumentException('KlikQRIS withdrawal query page is invalid.');
            }

            if ($this->startDate !== null && $this->endDate !== null && $this->startDate > $this->endDate) {
                throw new InvalidArgumentException('KlikQRIS withdrawal query date range is invalid.');
            }
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw new InvalidArgumentException('KlikQRIS withdrawal query is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'order_id' => $this->orderId,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'limit' => $this->limit,
            'page' => $this->page,
        ], static fn (int|string|null $value): bool => $value !== null);
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function orderId(): ?string
    {
        return $this->orderId;
    }

    public function startDate(): ?string
    {
        return $this->startDate;
    }

    public function endDate(): ?string
    {
        return $this->endDate;
    }

    public function limit(): ?int
    {
        return $this->limit;
    }

    public function page(): int
    {
        return $this->page;
    }

    private static function normalizeStatus(?string $status): ?string
    {
        $status = self::optionalText($status);
        if ($status === null) {
            return null;
        }

        $status = strtolower($status);
        if (! in_array($status, ['success', 'pending'], true)) {
            throw new InvalidArgumentException('KlikQRIS withdrawal query status is invalid.');
        }

        return $status;
    }

    private static function optionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function normalizeDate(?string $value): ?string
    {
        $value = self::optionalText($value);
        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'Asia/Jakarta');
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('KlikQRIS withdrawal query date is invalid.');
        }

        return $value;
    }

    private static function normalizeLimit(?int $limit): ?int
    {
        if ($limit === null) {
            return null;
        }

        $max = config('services.klikqris.reporting_max_limit', self::DEFAULT_MAX_LIMIT);
        $max = is_numeric($max) ? (int) $max : self::DEFAULT_MAX_LIMIT;
        $max = min(self::MAX_CONFIGURED_LIMIT, max(1, $max));
        if ($limit < 1 || $limit > $max) {
            throw new InvalidArgumentException('KlikQRIS withdrawal query limit is invalid.');
        }

        return $limit;
    }
}
