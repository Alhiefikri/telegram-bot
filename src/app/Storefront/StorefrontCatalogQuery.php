<?php

namespace App\Storefront;

use Illuminate\Http\Request;

final readonly class StorefrontCatalogQuery
{
    public const DEFAULT_SORT = 'recommended';

    /** @var list<string> */
    public const SORTS = [
        self::DEFAULT_SORT,
        'newest',
        'price_asc',
        'price_desc',
    ];

    public const PRODUCTS_PER_PAGE = 24;

    private const MAX_CATEGORY_LENGTH = 255;

    public function __construct(
        public string $search = '',
        public ?string $category = null,
        public string $sort = self::DEFAULT_SORT,
        public int $page = 1,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: StorefrontCatalogSearch::fromRequestQuery($request->query('q')),
            category: self::normalizeCategory($request->query('category')),
            sort: self::normalizeSort($request->query('sort')),
            page: self::normalizePage($request->query('page')),
        );
    }

    public static function fromSearch(?string $search): self
    {
        return new self(search: StorefrontCatalogSearch::normalize($search));
    }

    public function withCategory(?string $category): self
    {
        return new self($this->search, self::normalizeCategory($category), $this->sort, $this->page);
    }

    public function withoutCategory(): self
    {
        return $this->withCategory(null);
    }

    public function withoutSearch(): self
    {
        return new self('', $this->category, $this->sort, $this->page);
    }

    public function withPage(int $page): self
    {
        return new self($this->search, $this->category, $this->sort, self::normalizePage($page));
    }

    public function withoutPage(): self
    {
        return $this->withPage(1);
    }

    /**
     * @return array<string, int|string>
     */
    public function toUrlParameters(bool $includePage = true): array
    {
        $parameters = [];

        if ($this->search !== '') {
            $parameters['q'] = $this->search;
        }

        if ($this->category !== null) {
            $parameters['category'] = $this->category;
        }

        if ($this->sort !== self::DEFAULT_SORT) {
            $parameters['sort'] = $this->sort;
        }

        if ($includePage && $this->page > 1) {
            $parameters['page'] = $this->page;
        }

        return $parameters;
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
        if ($category === '' || strlen($category) > self::MAX_CATEGORY_LENGTH) {
            return null;
        }

        if (ctype_digit($category)) {
            return (int) $category > 0 ? (string) (int) $category : null;
        }

        return preg_match('/\A[a-z0-9]+(?:[-_][a-z0-9]+)*\z/', $category) === 1
            ? $category
            : null;
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

        if (! is_string($value)) {
            return 1;
        }

        $page = trim($value);
        if ($page === '' || ! ctype_digit($page)) {
            return 1;
        }

        if (strlen($page) > 18) {
            return PHP_INT_MAX;
        }

        return max(1, (int) $page);
    }
}
