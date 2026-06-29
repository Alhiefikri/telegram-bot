<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\StorefrontController;
use Illuminate\Support\Facades\Route;

Route::get('/', StorefrontController::class)->name('storefront.home');
Route::get('/admin', AdminDashboardController::class)->name('admin.dashboard');
