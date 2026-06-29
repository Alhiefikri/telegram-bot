<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminProductCreateController;
use App\Http\Controllers\Admin\AdminProductStockController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontController::class)->name('storefront.home');
Route::get('/admin', AdminDashboardController::class)->name('admin.dashboard');
Route::post('/admin/products', AdminProductCreateController::class)->name('admin.products.store');
Route::post('/admin/products/{product}/stock', AdminProductStockController::class)->name('admin.products.stock.store');
