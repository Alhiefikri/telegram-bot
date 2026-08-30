<?php

namespace App\Catalog;

final readonly class CatalogPagination
{
    public function __construct(
        public int $currentPage,
        public int $lastPage,
        public int $total,
        public int $perPage,
        public ?int $from,
        public ?int $to,
    ) {}

    public function hasPages(): bool
    {
        return $this->lastPage > 1;
    }

    /** @return array<string, int|bool|null> */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage,
            'last_page' => $this->lastPage,
            'total' => $this->total,
            'per_page' => $this->perPage,
            'from' => $this->from,
            'to' => $this->to,
            'has_pages' => $this->hasPages(),
        ];
    }
}
