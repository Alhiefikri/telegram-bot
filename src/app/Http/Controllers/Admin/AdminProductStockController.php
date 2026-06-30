<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\StockAdjustmentService;
use App\Services\Telegram\TelegramProductNotificationService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class AdminProductStockController extends Controller
{
    public function __invoke(Request $request, Product $product, StockAdjustmentService $stockAdjustment, TelegramProductNotificationService $notifications): RedirectResponse
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('filament.admin.auth.login'));
        }

        $panel = Filament::getPanel('admin');

        abort_unless($user->canAccessPanel($panel), 403);

        Gate::authorize('adjustStock', $product);

        $validator = Validator::make($request->all(), [
            'stock_items' => ['required', 'string', 'max:5000'],
        ], [
            'stock_items.required' => 'Isi minimal satu baris stok.',
            'stock_items.max' => 'Catatan stok maksimal 5000 karakter.',
        ]);

        if ($validator->fails()) {
            return $this->redirectBackWithStockErrors($request, $validator->errors()->toArray());
        }

        $stockItems = $this->stockItems((string) $validator->validated()['stock_items']);

        if ($stockItems === []) {
            return $this->redirectBackWithStockErrors($request, [
                'stock_items' => ['Isi minimal satu baris stok.'],
            ]);
        }

        $itemCount = count($stockItems);
        $notes = "Tambah stok manual dashboard ({$itemCount} item).";

        $adjusted = $stockAdjustment->adjust($product, $itemCount, $notes, $user, $request->ip(), $stockItems);
        $notifications->notifyStockAdded($adjusted, $itemCount);

        return redirect()
            ->route('admin.dashboard', ['tab' => 'produk'])
            ->with('status', "Stok {$product->name} bertambah {$itemCount} item.");
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

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function redirectBackWithStockErrors(Request $request, array $errors): RedirectResponse
    {
        return redirect()
            ->back()
            ->withErrors($errors, 'stockAdjustment')
            ->withInput($request->only(['stock_items', 'stock_product_id']));
    }
}
