<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupplierAdminRequest;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Services\Admin\AdminSupplierDashboardData;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierManager;
use App\Services\Suppliers\SupplierMoney;
use App\Services\Suppliers\SupplierOrderHistoryReconciliationService;
use App\Services\Suppliers\SupplierPricing;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AdminSupplierController extends Controller
{
    public function store(SupplierAdminRequest $request, SupplierManager $manager)
    {
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        $manager->save(null, $request->validated(), $request->attributes->get('adminUser'), $request->ip());

        return $this->saved('Supplier dibuat. Sinkronkan sebelum mengaktifkan pemetaan.');
    }

    public function update(SupplierAdminRequest $request, Supplier $supplier, SupplierManager $manager)
    {
        if ($request->filled('api_key') || $request->filled('webhook_secret') || $request->input('provider') !== $supplier->provider) {
            app(SupplierOperationConfirmation::class)->requireRecent($request);
        }

        return $this->mutate(fn () => $manager->save($supplier, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Supplier diperbarui. Sinkronisasi ulang diperlukan.');
    }

    public function sync(SupplierAdminRequest $request, Supplier $supplier, SupplierCatalogSync $sync, AdminSupplierDashboardData $dashboard)
    {
        // This POST is only a CSRF-protected local action. Provider snapshot is GET-only.
        $applied = $sync->sync($supplier);

        return $this->saved($applied ? 'Katalog dan saldo disinkronkan.' : $dashboard->syncFailureMessage($supplier->refresh()));
    }

    public function catalog(Request $request, Supplier $supplier)
    {
        $this->authorizeManager($request);
        $items = SupplierCatalogItem::query()->where('supplier_id', $supplier->id)->with(['sources.product'])->orderBy('id')->paginate(25);
        $products = Product::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'price_amount', 'is_active']);

        return view('admin.suppliers.catalog', ['supplier' => $supplier->only(['id', 'name', 'provider', 'currency', 'exchange_rate_idr', 'version', 'synced_at']), 'items' => $items, 'products' => $products]);
    }

    public function import(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        return $this->mutate(function () use ($request, $catalogItem, $manager): void {
            $manager->import($catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        }, 'Draft produk tersedia. Edit di tab Produk sebelum dipublikasikan.');
    }

    public function map(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        return $this->mutate(fn () => $manager->map(Product::query()->findOrFail($request->integer('product_id')), $catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Pemetaan supplier disimpan.');
    }

    public function move(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));
        try {
            $manager->move($product, $catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return redirect()->route('admin.products.suppliers', $product->id)->withErrors($exception->errors())->with('status', 'Urutan tidak berubah. Muat ulang halaman lalu coba lagi.');
        }

        return redirect()->route('admin.products.suppliers', $product->id)->with('status', 'Urutan supplier diperbarui.');
    }

    public function detach(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));
        try {
            $manager->detach($product, $catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Pemetaan berubah. Muat ulang sebelum melepas mapping.', 'errors' => $exception->errors()], 422);
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() !== 409) {
                throw $exception;
            }

            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return redirect()->route('admin.products.suppliers', $product)->with('status', 'Mapping supplier dilepas. Stok lokal, harga, dan riwayat tetap tersimpan.');
    }

    public function product(Request $request, Product $product, SupplierPricing $pricing)
    {
        $this->authorizeManager($request);
        $sources = ProductSupplierSource::query()->where('product_id', $product->id)->with('catalogItem.supplier')->orderBy('priority')->orderBy('id')->get();
        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->first();

        return view('admin.suppliers.product', ['product' => $product, 'sources' => $sources, 'policy' => $policy, 'sourceCosts' => $this->sourceCosts($sources, $pricing)]);
    }

    public function price(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager)
    {
        return $this->mutate(fn () => $manager->price($product, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Kebijakan harga supplier disimpan.');
    }

    public function preview(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager, SupplierPricing $pricing)
    {
        $item = SupplierCatalogItem::query()->findOrFail($request->integer('catalog_item_id'));
        abort_unless(ProductSupplierSource::query()->where('product_id', $product->id)->where('supplier_catalog_item_id', $item->id)->exists(), 422);

        $input = $request->validated();
        $pricePreview = $manager->preview($product, $item, $input);
        if ($request->expectsJson()) {
            return response()->json($pricePreview);
        }

        $sources = ProductSupplierSource::query()->where('product_id', $product->id)->with('catalogItem.supplier')->orderBy('priority')->orderBy('id')->get();
        $policy = new SupplierPricePolicy;
        $policy->forceFill($input);

        return view('admin.suppliers.product', [
            'product' => $product,
            'sources' => $sources,
            'policy' => $policy,
            'pricePreview' => $pricePreview,
            'previewItem' => $item,
            'sourceCosts' => $this->sourceCosts($sources, $pricing),
        ]);
    }

    public function purchases(Request $request)
    {
        $this->authorizeManager($request);
        $purchases = SupplierPurchase::query()->select(['id', 'supplier_fulfillment_id', 'supplier_id', 'reference', 'supplier_order_id', 'quantity', 'expected_cost_micros', 'actual_cost_micros', 'state', 'last_error', 'created_at', 'updated_at'])->orderByDesc('id')->paginate(25);
        $fulfillments = SupplierFulfillment::query()->with(['currentPurchase.supplier', 'currentPurchase.latestHistoryReconciliation'])
            ->select(['id', 'order_id', 'order_item_id', 'quantity', 'state', 'last_error', 'current_purchase_id', 'created_at'])
            ->where(fn ($query) => $query->whereNotIn('state', ['delivered', 'cancelled'])
                ->orWhereHas('purchases', fn ($purchases) => $purchases->whereNotIn('state', ['delivered', 'settled', 'rejected', 'cancelled'])))
            ->orderByDesc('id')->paginate(25, ['*'], 'fulfillments_page');

        return view('admin.suppliers.purchases', compact('purchases', 'fulfillments'));
    }

    public function operate(SupplierAdminRequest $request, SupplierFulfillment $fulfillment, SupplierFulfillmentService $service, SupplierOrderHistoryReconciliationService $history)
    {
        app(SupplierOperationConfirmation::class)->requireRecent($request);
        try {
            match ($request->input('action')) {
                'retry' => $service->retryAfterTopup((int) $fulfillment->id),
                'recover' => $service->recoverOrder((int) $fulfillment->id, $request->string('supplier_order_id')->toString()),
                'manual' => $service->provideManualCredentials((int) $fulfillment->id, array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $request->string('credentials')->toString())), fn (string $value): bool => $value !== ''))),
                'no_debit' => $service->confirmNoDebit(
                    (int) $fulfillment->id,
                    $request->attributes->get('adminUser'),
                    $request->string('proof_reference')->toString(),
                    $request->string('proof_note')->toString(),
                    $request->ip(),
                    $request->string('proof_source')->toString(),
                    $request->filled('history_reconciliation_id') ? $request->integer('history_reconciliation_id') : null,
                    $request->integer('purchase_id'),
                ),
                'debit_confirmed' => $service->confirmDebit(
                    (int) $fulfillment->id,
                    $request->attributes->get('adminUser'),
                    $request->string('supplier_order_id')->toString(),
                    $request->integer('debit_quantity'),
                    $request->integer('debit_cost_micros'),
                    $request->string('proof_note')->toString(),
                    $request->ip(),
                    $request->integer('purchase_id'),
                ),
                'history' => $history->reconcile(
                    (int) $fulfillment->id,
                    $request->attributes->get('adminUser'),
                    $request->string('history_timezone')->toString(),
                    $request->ip(),
                    $request->integer('purchase_id'),
                ),
            };
        } catch (InvalidOrderTransition|InsufficientStock|\DomainException|InvalidArgumentException|ValidationException $exception) {
            // Operational errors may contain delivery data: never echo them.
            return response()->json(['message' => 'Tindakan tidak aman untuk status fulfillment saat ini.'], 409);
        }

        return redirect()->route('admin.suppliers.purchases')->with('status', 'Tindakan supplier diproses. Periksa status dan bukti sebelum tindakan berikutnya.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
    }

    /** Private read-only unit cost per mapped source; display only, never a purchase authorization. */
    private function sourceCosts(Collection $sources, SupplierPricing $pricing): array
    {
        $costs = [];
        foreach ($sources as $source) {
            $item = $source->catalogItem;
            $supplier = $item?->supplier;
            $micros = $item === null ? null : $pricing->costMicros($item, 1);
            try {
                $idr = $micros === null || $supplier === null ? null : SupplierMoney::costIdr($micros, (int) $supplier->exchange_rate_idr);
            } catch (InvalidArgumentException) {
                $idr = null;
            }
            $costs[$source->id] = [
                'unit' => $micros === null ? null : rtrim(rtrim(SupplierMoney::decimal($micros), '0'), '.'),
                'idr' => $idr,
            ];
        }

        return $costs;
    }

    private function mutate(callable $mutation, string $message)
    {
        try {
            $mutation();
        } catch (ValidationException $exception) {
            $conflict = array_key_exists('version', $exception->errors()) || array_key_exists('connection', $exception->errors());

            return response()->json(['message' => $conflict ? 'Konfigurasi berubah atau komitmen supplier masih aktif. Muat ulang.' : 'Pemetaan atau kebijakan tidak valid.', 'errors' => $exception->errors()], $conflict ? 409 : 422);
        }

        return $this->saved($message);
    }

    private function saved(string $message)
    {
        if (request()->expectsJson()) {
            request()->session()->flash('status', $message);

            return response()->json(['message' => $message, 'redirect_url' => route('admin.dashboard', ['tab' => 'suppliers'])]);
        }

        return redirect()->route('admin.dashboard', ['tab' => 'suppliers'])->with('status', $message);
    }
}
