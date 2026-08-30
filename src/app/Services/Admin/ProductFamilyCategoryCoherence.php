<?php

namespace App\Services\Admin;

use App\Exceptions\ProductFamilyCategoryMismatch;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ProductFamilyCategoryCoherence
{
    /**
     * Lock the family, all of its members, and relevant categories before a
     * Product category or membership mutation. Family rows are always locked
     * before member Product rows so concurrent family mutations serialize on
     * the same family boundary.
     *
     * @return array{product: Product, family: ?ProductFamily, families: Collection<int, ProductFamily>, products: Collection<int, Product>}
     */
    public function lockForMembership(Product $product, ?int $targetFamilyId): array
    {
        $productId = (int) $product->getKey();
        $currentFamilyId = Product::query()->whereKey($productId)->value('product_family_id');
        $familyIds = $this->familyIds($currentFamilyId, $targetFamilyId);

        $families = $this->lockFamilies($familyIds);
        $products = $this->lockProducts($this->memberIds($familyIds, $productId));

        $lockedProduct = $products->firstWhere('id', $productId);
        if (! $lockedProduct instanceof Product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        // The first read can predate a completed membership mutation. The
        // family locks make a second, bounded read sufficient to include the
        // committed family without weakening the lock boundary.
        $resolvedFamilyId = $lockedProduct->product_family_id === null
            ? null
            : (int) $lockedProduct->product_family_id;
        if ($resolvedFamilyId !== null && ! in_array($resolvedFamilyId, $familyIds, true)) {
            $familyIds = $this->familyIds($resolvedFamilyId, $targetFamilyId);
            $families = $this->lockFamilies($familyIds);
            $products = $this->lockProducts($this->memberIds($familyIds, $productId));
            $lockedProduct = $products->firstWhere('id', $productId);
        }

        $this->lockCategories($this->categoryIds($products, $lockedProduct, $lockedProduct->category_id));
        $family = $targetFamilyId === null ? null : $families->get($targetFamilyId);

        if ($family instanceof ProductFamily) {
            $this->assertCompatible($lockedProduct, $family, $products);
        }

        return [
            'product' => $lockedProduct,
            'family' => $family,
            'families' => $families,
            'products' => $products,
        ];
    }

    /**
     * Lock a Product and every member of its current family before changing
     * its category. The caller remains responsible for the surrounding
     * transaction and Product update.
     */
    public function lockForCategoryChange(Product $product, ?int $newCategoryId): Product
    {
        $productId = (int) $product->getKey();
        $currentFamilyId = Product::query()->whereKey($productId)->value('product_family_id');
        $familyIds = $this->familyIds($currentFamilyId, null);
        $families = $this->lockFamilies($familyIds);
        $products = $this->lockProducts($this->memberIds($familyIds, $productId));
        $lockedProduct = $products->firstWhere('id', $productId);

        if (! $lockedProduct instanceof Product) {
            throw (new ModelNotFoundException)->setModel(Product::class, [$productId]);
        }

        $resolvedFamilyId = $lockedProduct->product_family_id === null
            ? null
            : (int) $lockedProduct->product_family_id;
        if ($resolvedFamilyId !== null && ! in_array($resolvedFamilyId, $familyIds, true)) {
            $familyIds = $this->familyIds($resolvedFamilyId, null);
            $families = $this->lockFamilies($familyIds);
            $products = $this->lockProducts($this->memberIds($familyIds, $productId));
            $lockedProduct = $products->firstWhere('id', $productId);
        }
        $this->lockCategories($this->categoryIds($products, $lockedProduct, $newCategoryId));
        $family = $resolvedFamilyId === null ? null : $families->get($resolvedFamilyId);

        if ($family instanceof ProductFamily) {
            $this->assertCompatible($lockedProduct, $family, $products, $newCategoryId);
        }

        return $lockedProduct;
    }

    /**
     * Lock all Products affected by Category deletion with the same family
     * boundary used by membership mutations. CategoryManagementService then
     * performs its single bulk nulling update inside the same transaction.
     */
    public function lockForCategoryDeletion(int $categoryId): void
    {
        $familyIds = Product::withTrashed()
            ->where('category_id', $categoryId)
            ->whereNotNull('product_family_id')
            ->orderBy('product_family_id')
            ->pluck('product_family_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->lockFamilies($familyIds);

        $productIds = Product::withTrashed()
            ->where('category_id', $categoryId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $this->lockProducts($productIds);
        $this->lockCategories([$categoryId]);
    }

    /**
     * Non-locking validation helper used to provide an actionable FormRequest
     * error before the authoritative transaction check.
     */
    public function validationMessage(Product $product, ?int $newCategoryId): ?string
    {
        $family = $product->family;
        if (! $family instanceof ProductFamily) {
            return null;
        }

        $members = $family->products()
            ->where('id', '!=', $product->getKey())
            ->select(['id', 'category_id'])
            ->get();

        return $this->compatible($newCategoryId, $members) ? null : $this->message($family);
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function assertCompatible(Product $product, ProductFamily $family, Collection $products, ?int $candidateCategoryId = null): void
    {
        $candidateCategoryId ??= $product->category_id === null ? null : (int) $product->category_id;
        $members = $products->filter(fn (Product $member): bool => (int) $member->product_family_id === (int) $family->getKey());

        if (! $this->compatible($candidateCategoryId, $members, $product->getKey())) {
            throw new ProductFamilyCategoryMismatch($this->message($family));
        }
    }

    /**
     * @param  Collection<int, Product>  $members
     */
    private function compatible(?int $candidateCategoryId, Collection $members, mixed $ignoreProductId = null): bool
    {
        $expectedCategoryId = null;
        $hasExpectedCategory = false;

        foreach ($members as $member) {
            if ($ignoreProductId !== null && (int) $member->getKey() === (int) $ignoreProductId) {
                continue;
            }

            $memberCategoryId = $member->category_id === null ? null : (int) $member->category_id;
            if (! $hasExpectedCategory) {
                $expectedCategoryId = $memberCategoryId;
                $hasExpectedCategory = true;

                continue;
            }

            if ($memberCategoryId !== $expectedCategoryId) {
                return false;
            }
        }

        return ! $hasExpectedCategory || $candidateCategoryId === $expectedCategoryId;
    }

    private function message(ProductFamily $family): string
    {
        return "Product Family \"{$family->name}\" hanya dapat berisi Product dengan kategori yang sama. Pilih kategori anggota yang sudah ada atau keluarkan Product dari family terlebih dahulu.";
    }

    /** @return list<int> */
    private function familyIds(mixed $currentFamilyId, ?int $targetFamilyId): array
    {
        return collect([$currentFamilyId, $targetFamilyId])
            ->filter(fn (mixed $id): bool => $id !== null && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @return list<int> */
    private function memberIds(array $familyIds, int $productId): array
    {
        $ids = Product::withTrashed()
            ->when($familyIds !== [], fn ($query) => $query->whereIn('product_family_id', $familyIds))
            ->when($familyIds === [], fn ($query) => $query->whereKey($productId))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $ids[] = $productId;

        return collect($ids)->unique()->sort()->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, ProductFamily>
     */
    private function lockFamilies(array $ids): Collection
    {
        $families = new Collection;
        foreach ($ids as $id) {
            $family = ProductFamily::query()->whereKey($id)->lockForUpdate()->first();
            if (! $family instanceof ProductFamily) {
                throw (new ModelNotFoundException)->setModel(ProductFamily::class, [$id]);
            }
            $families->put($id, $family);
        }

        return $families;
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function lockProducts(array $ids): Collection
    {
        $products = new Collection;
        foreach ($ids as $id) {
            $product = Product::withTrashed()->whereKey($id)->lockForUpdate()->first();
            if (! $product instanceof Product) {
                throw (new ModelNotFoundException)->setModel(Product::class, [$id]);
            }
            $products->put($id, $product);
        }

        return $products;
    }

    /** @param list<int> $ids */
    private function lockCategories(array $ids): void
    {
        foreach (collect($ids)->filter(fn (mixed $id): bool => $id !== null)->map(fn (mixed $id): int => (int) $id)->unique()->sort() as $id) {
            Category::withTrashed()->whereKey($id)->lockForUpdate()->first();
        }
    }

    /** @param Collection<int, Product> $products */
    private function categoryIds(Collection $products, Product $product, mixed $candidateCategoryId): array
    {
        return $products
            ->pluck('category_id')
            ->push($product->category_id)
            ->push($candidateCategoryId)
            ->filter(fn (mixed $id): bool => $id !== null)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
