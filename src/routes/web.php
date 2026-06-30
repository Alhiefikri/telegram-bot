<?php

use App\Http\Controllers\Admin\AdminBroadcastController;
use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminPaymentModeController;
use App\Http\Controllers\Admin\AdminProductCreateController;
use App\Http\Controllers\Admin\AdminProductDeleteController;
use App\Http\Controllers\Admin\AdminProductStockController;
use App\Http\Controllers\Admin\AdminProductUpdateController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontController::class)->name('storefront.home');
Route::get('/admin', AdminDashboardController::class)->name('admin.dashboard');
Route::post('/admin/payment-mode', AdminPaymentModeController::class)->name('admin.payment-mode.update');
Route::post('/admin/broadcast', AdminBroadcastController::class)->name('admin.broadcast.store');
Route::get('/admin/login', [AdminLoginController::class, 'create'])->name('admin.login');
Route::post('/admin/login', [AdminLoginController::class, 'store'])->name('admin.login.store');
Route::post('/admin/logout', [AdminLoginController::class, 'destroy'])->name('admin.logout');
Route::patch('/admin/products/{product}', AdminProductUpdateController::class)->name('admin.products.update');
Route::delete('/admin/products/{product}', AdminProductDeleteController::class)->name('admin.products.destroy');
Route::post('/admin/products', AdminProductCreateController::class)->name('admin.products.store');
Route::post('/admin/products/{product}/stock', AdminProductStockController::class)->name('admin.products.stock.store');
