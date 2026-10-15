<?php

namespace App\Catalog;

final readonly class CatalogBrowseQuery
{
    public const DEFAULT_SORT = 'recommended';

    /** @var list<string> */
    public const SORTS = [self::DEFAULT_SORT, 'newest', 'price_asc', 'price_desc'];

    public const ADMIN_SORTS = [
        'name_asc' => 'Nama A–Z',
        'name_desc' => 'Nama Z–A',
        'members_desc' => 'Jumlah produk terbanyak',
        'members_asc' => 'Jumlah produk tersedikit',
        'newest' => 'Terbaru',
        'price_asc' => 'Harga terendah',
        'price_desc' => 'Harga tertinggi',
        'recommended' => 'Urutan katalog (tersedia, family, prioritas, nama)',
    ];

    public const DEFAULT_PER_PAGE = 24;

    public function __construct(
        public string $search = '',
        public ?string $category = null,
        public string $sort = self::DEFAULT_SORT,
        public int $page = 1,
        public int $perPage = self::DEFAULT_PER_PAGE,
    ) {}

    public static function fromValues(
        ?string $search = null,
        mixed $category = null,
        mixed $sort = null,
        mixed $page = null,
        int $perPage = self::DEFAULT_PER_PAGE,
        bool $admin = false,
    ): self {
        return new self(
            search: self::normalizeSearch($search),
            category: self::normalizeCategory($category),
            sort: $admin && is_string($sort) && isset(self::ADMIN_SORTS[$sort]) ? $sort : self::normalizeSort($sort),
            page: self::normalizePage($page),
            perPage: max(1, min(100, $perPage)),
        );
    }

    public static function normalizeSearch(?string $value): string
    {
        $search = trim((string) $value);
        if ($search === '') {
            return '';
        }

        $collapsed = preg_replace('/\s+/u', ' ', $search);
        if (is_string($collapsed)) {
            $search = $collapsed;
        }

        return function_exists('mb_strtolower') ? mb_strtolower($search, 'UTF-8') : strtolower($search);
    }

    public function withPage(int $page): self
    {
        return new self($this->search, $this->category, $this->sort, max(1, $page), $this->perPage);
    }

    public function withoutPage(): self
    {
        return $this->withPage(1);
    }

    private static function normalizeCategory(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $category = strtolower(trim($value));
        if ($category === '' || strlen($category) > 255) {
            return null;
        }

        if (ctype_digit($category)) {
            return (int) $category > 0 ? (string) (int) $category : null;
        }

        return preg_match('/\A[a-z0-9]+(?:[-_][a-z0-9]+)*\z/', $category) === 1 ? $category : null;
    }

    private static function normalizeSort(mixed $value): string
    {
        if (! is_string($value)) {
            return self::DEFAULT_SORT;
        }

        $sort = strtolower(trim($value));

        return in_array($sort, self::SORTS, true) ? $sort : self::DEFAULT_SORT;
    }

    private static function normalizePage(mixed $value): int
    {
        if (is_int($value)) {
            return max(1, $value);
        }

        if (! is_string($value) || trim($value) === '' || ! ctype_digit(trim($value))) {
            return 1;
        }

        return max(1, (int) trim($value));
    }
}
