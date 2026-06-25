<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $categories = Category::query()
        ->select(['id', 'name', 'slug', 'description', 'sort_order'])
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->orderBy('name')
        ->limit(8)
        ->get();

    $products = Product::query()
        ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'currency', 'stock', 'reserved_stock', 'image_path'])
        ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->where('is_active', true)])
        ->where('is_active', true)
        ->where(fn (Builder $query) => $query
            ->whereNull('category_id')
            ->orWhereHas('category', fn (Builder $category) => $category->where('is_active', true)))
        ->orderByDesc('stock')
        ->orderBy('name')
        ->limit(6)
        ->get();

    return view('storefront', [
        'categories' => $categories,
        'products' => $products,
    ]);
})->name('storefront.home');
