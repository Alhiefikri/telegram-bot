<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $search = trim((string) request('q'));

    $categories = Category::query()
        ->select(['id', 'name', 'slug', 'description', 'sort_order'])
        ->publicVisible()
        ->storefrontOrder()
        ->limit(8)
        ->get();

    $featuredProduct = Product::query()
        ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'currency', 'stock', 'reserved_stock', 'image_path'])
        ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
        ->publicVisible()
        ->whereColumn('stock', '>', 'reserved_stock')
        ->storefrontOrder()
        ->first();

    $products = Product::query()
        ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'currency', 'stock', 'reserved_stock', 'image_path'])
        ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
        ->publicVisible()
        ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
            ->where('name', 'like', "%{$search}%")
            ->orWhere('description', 'like', "%{$search}%")))
        ->storefrontOrder()
        ->limit(24)
        ->get();

    return view('storefront', [
        'categories' => $categories,
        'products' => $products,
        'featuredProduct' => $featuredProduct,
        'search' => $search,
    ]);
})->name('storefront.home');
