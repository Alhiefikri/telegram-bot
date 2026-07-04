<?php

namespace App\Storefront;

use App\Models\Category;
use App\Models\Product;

class StorefrontCatalogQuery
{
    /**
     * @return array{categories: mixed, products: mixed, featuredProduct: Product|null, search: string}
     */
    public function get(?string $rawSearch): array
    {
        $search = trim((string) $rawSearch);

        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'description', 'sort_order'])
            ->publicVisible()
            ->storefrontOrder()
            ->limit(8)
            ->get();

        $featuredProduct = Product::query()
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'fake_discount_price', 'currency', 'stock', 'reserved_stock', 'image_path'])
            ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
            ->publicVisible()
            ->whereColumn('stock', '>', 'reserved_stock')
            ->storefrontOrder()
            ->first();

        $products = Product::query()
            ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'fake_discount_price', 'currency', 'stock', 'reserved_stock', 'image_path'])
            ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
            ->publicVisible()
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->storefrontOrder()
            ->limit(24)
            ->get();

        return [
            'categories' => $categories,
            'products' => $products,
            'featuredProduct' => $featuredProduct,
            'search' => $search,
        ];
    }
}
