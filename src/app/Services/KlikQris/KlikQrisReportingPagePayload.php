<?php

namespace App\Services\KlikQris;

use UnexpectedValueException;

final readonly class KlikQrisReportingPagePayload
{
    /**
     * @param  list<array<mixed>>  $rows
     */
    private function __construct(
        private array $rows,
        private int $currentPage,
        private int $perPage,
        private int $total,
        private int $lastPage,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        if (($payload['status'] ?? null) !== true) {
            throw new UnexpectedValueException('KlikQRIS reporting status is not successful.');
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new UnexpectedValueException('KlikQRIS reporting paginator data is missing.');
        }

        $rows = $data['data'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new UnexpectedValueException('KlikQRIS reporting paginator rows are invalid.');
        }

        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new UnexpectedValueException('KlikQRIS reporting row is invalid.');
            }
        }

        foreach ([
            'current_page',
            'per_page',
            'total',
            'last_page',
            'first_page_url',
            'last_page_url',
            'next_page_url',
            'prev_page_url',
        ] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new UnexpectedValueException('KlikQRIS reporting paginator metadata is incomplete.');
            }
        }

        $currentPage = KlikQrisReportingInteger::positive($data['current_page']);
        $perPage = KlikQrisReportingInteger::positive($data['per_page']);
        $total = KlikQrisReportingInteger::nonNegative($data['total']);
        $lastPage = KlikQrisReportingInteger::positive($data['last_page']);

        if ($currentPage > $lastPage) {
            throw new UnexpectedValueException('KlikQRIS reporting paginator page is invalid.');
        }

        foreach (['first_page_url', 'last_page_url', 'next_page_url', 'prev_page_url'] as $key) {
            if ($data[$key] !== null && ! is_string($data[$key])) {
                throw new UnexpectedValueException('KlikQRIS reporting pagination URL is invalid.');
            }
        }

        return new self($rows, $currentPage, $perPage, $total, $lastPage);
    }

    /**
     * @return list<array<mixed>>
     */
    public function rows(): array
    {
        return $this->rows;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function perPage(): int
    {
        return $this->perPage;
    }

    public function total(): int
    {
        return $this->total;
    }

    public function lastPage(): int
    {
        return $this->lastPage;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }
}
