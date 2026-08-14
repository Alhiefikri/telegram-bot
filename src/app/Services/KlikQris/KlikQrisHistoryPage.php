<?php

namespace App\Services\KlikQris;

final readonly class KlikQrisHistoryPage
{
    /**
     * @param  list<KlikQrisHistoryItem>  $items
     */
    private function __construct(
        private array $items,
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
        $page = KlikQrisReportingPagePayload::fromPayload($payload);
        $items = array_map(
            static fn (array $row): KlikQrisHistoryItem => KlikQrisHistoryItem::fromPayload($row),
            $page->rows(),
        );

        return new self(
            items: $items,
            currentPage: $page->currentPage(),
            perPage: $page->perPage(),
            total: $page->total(),
            lastPage: $page->lastPage(),
        );
    }

    /**
     * @return list<KlikQrisHistoryItem>
     */
    public function items(): array
    {
        return $this->items;
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
