<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductStockRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\TelegramProductNotificationService;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;

class AdminProductStockController extends Controller
{
    public function __invoke(StoreProductStockRequest $request, Product $product, StockAdjustmentService $stockAdjustment, TelegramProductNotificationService $notifications, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        $stockItems = $request->stockItems();

        $itemCount = count($stockItems);
        $notes = "Tambah stok manual dashboard ({$itemCount} item).";

        $adjustment = $stockAdjustment->adjustWithAvailability($product, $itemCount, $notes, $user, $request->ip(), $stockItems);
        $result = $notifications->notifyStockAdded($adjustment['product'], $itemCount, $adjustment['available_before']);
        AdminTelegramDogfoodTiming::logRequestFinished('admin.product_stock_add', $startedAt, $result);

        return $forms->stockAdded($product->name, $itemCount);
    }
}
