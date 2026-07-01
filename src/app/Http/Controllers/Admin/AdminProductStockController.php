<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\AuthorizesAdminAccess;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AdminProductStockController extends Controller
{
    use AuthorizesAdminAccess;

    public function __invoke(Request $request, Product $product, StockAdjustmentService $stockAdjustment, TelegramProductNotificationService $notifications, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        if ($redirect = $this->redirectGuestToAdminLogin($request)) {
            return $redirect;
        }

        $user = $this->authorizeAdminAccess($request);

        Gate::authorize('adjustStock', $product);

        $validator = Validator::make($request->all(), [
            'stock_items' => ['required', 'string', 'max:5000'],
        ], [
            'stock_items.required' => 'Isi minimal satu baris stok.',
            'stock_items.max' => 'Catatan stok maksimal 5000 karakter.',
        ]);

        if ($validator->fails()) {
            return $forms->stockValidationErrors($request, $product, $validator->errors()->toArray());
        }

        $stockItems = $this->stockItems((string) $validator->validated()['stock_items']);

        if ($stockItems === []) {
            return $forms->stockValidationErrors($request, $product, [
                'stock_items' => ['Isi minimal satu baris stok.'],
            ]);
        }

        $itemCount = count($stockItems);
        $notes = "Tambah stok manual dashboard ({$itemCount} item).";

        $adjusted = $stockAdjustment->adjust($product, $itemCount, $notes, $user, $request->ip(), $stockItems);
        $notifications->notifyStockAdded($adjusted, $itemCount);

        return $forms->stockAdded($product->name, $itemCount);
    }

    /**
     * @return list<string>
     */
    private function stockItems(string $rawItems): array
    {
        return collect(preg_split('/\R/u', $rawItems) ?: [])
            ->map(fn (string $row): string => trim($row))
            ->filter()
            ->values()
            ->all();
    }
}
