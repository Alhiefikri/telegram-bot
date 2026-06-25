<?php

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $categories = Category::query()
        ->select(['id', 'name', 'slug', 'description', 'sort_order'])
        ->publicVisible()
        ->storefrontOrder()
        ->limit(8)
        ->get();

    $products = Product::query()
        ->select(['id', 'category_id', 'name', 'description', 'price_amount', 'currency', 'stock', 'reserved_stock', 'image_path'])
        ->with(['category' => fn ($query) => $query->select(['id', 'name', 'slug'])->publicVisible()])
        ->publicVisible()
        ->storefrontOrder()
        ->limit(6)
        ->get();

    return view('storefront', [
        'categories' => $categories,
        'products' => $products,
    ]);
})->name('storefront.home');
