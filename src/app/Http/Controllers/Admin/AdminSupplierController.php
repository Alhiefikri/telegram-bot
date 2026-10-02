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
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminSupplierController extends Controller
{
    public function store(SupplierAdminRequest $request, SupplierManager $manager)
    {
        $this->reauthenticate($request);
        $manager->save(null, $request->validated(), $request->attributes->get('adminUser'), $request->ip());

        return $this->saved('Supplier dibuat. Sinkronkan sebelum mengaktifkan pemetaan.');
    }

    public function update(SupplierAdminRequest $request, Supplier $supplier, SupplierManager $manager)
    {
        if ($request->filled('api_key') || $request->input('provider') !== $supplier->provider) {
            $this->reauthenticate($request);
        }

        return $this->mutate(fn () => $manager->save($supplier, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Supplier diperbarui. Sinkronisasi ulang diperlukan.');
    }

    public function sync(SupplierAdminRequest $request, Supplier $supplier, SupplierCatalogSync $sync)
    {
        // This POST is only a CSRF-protected local action. Provider snapshot is GET-only.
        $applied = $sync->sync($supplier);

        return $this->saved($applied ? 'Katalog dan saldo disinkronkan.' : 'Supplier belum tersedia atau konfigurasi berubah. Tidak ada observasi yang ditimpa.');
    }

    public function catalog(Request $request, Supplier $supplier)
    {
        $this->authorizeManager($request);
        $items = SupplierCatalogItem::query()->where('supplier_id', $supplier->id)->with(['sources.product'])->orderBy('id')->paginate(25);

        return view('admin.suppliers.catalog', ['supplier' => $supplier->only(['id', 'name', 'provider', 'currency', 'exchange_rate_idr', 'version', 'synced_at']), 'items' => $items]);
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

    public function product(Request $request, Product $product)
    {
        $this->authorizeManager($request);
        $sources = ProductSupplierSource::query()->where('product_id', $product->id)->with('catalogItem.supplier')->orderBy('priority')->orderBy('id')->get();
        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->first();

        return view('admin.suppliers.product', compact('product', 'sources', 'policy'));
    }

    public function price(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager)
    {
        return $this->mutate(fn () => $manager->price($product, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Kebijakan harga supplier disimpan.');
    }

    public function preview(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager)
    {
        $item = SupplierCatalogItem::query()->findOrFail($request->integer('catalog_item_id'));
        abort_unless(ProductSupplierSource::query()->where('product_id', $product->id)->where('supplier_catalog_item_id', $item->id)->exists(), 422);

        return response()->json($manager->preview($product, $item, $request->validated()));
    }

    public function purchases(Request $request)
    {
        $this->authorizeManager($request);
        $purchases = SupplierPurchase::query()->select(['id', 'supplier_fulfillment_id', 'supplier_id', 'reference', 'supplier_order_id', 'quantity', 'expected_cost_micros', 'actual_cost_micros', 'state', 'last_error', 'created_at', 'updated_at'])->orderByDesc('id')->paginate(25);
        $fulfillments = SupplierFulfillment::query()->select(['id', 'order_id', 'order_item_id', 'quantity', 'state', 'last_error', 'created_at'])
            ->where(fn ($query) => $query->whereNotIn('state', ['delivered', 'cancelled'])
                ->orWhereHas('purchases', fn ($purchases) => $purchases->whereNotIn('state', ['delivered', 'settled', 'rejected', 'cancelled'])))
            ->orderByDesc('id')->paginate(25, ['*'], 'fulfillments_page');

        return view('admin.suppliers.purchases', compact('purchases', 'fulfillments'));
    }

    public function operate(SupplierAdminRequest $request, SupplierFulfillment $fulfillment, SupplierFulfillmentService $service)
    {
        $this->reauthenticate($request);
        try {
            match ($request->input('action')) {
                'retry' => $service->retryAfterTopup((int) $fulfillment->id),
                'recover' => $service->recoverOrder((int) $fulfillment->id, $request->string('supplier_order_id')->toString()),
                'manual' => $service->provideManualCredentials((int) $fulfillment->id, array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $request->string('credentials')->toString())), fn (string $value): bool => $value !== ''))),
            };
        } catch (InvalidOrderTransition|InsufficientStock|\DomainException|\InvalidArgumentException|ValidationException $exception) {
            // Operational errors may contain delivery data: never echo them.
            return response()->json(['message' => 'Tindakan tidak aman untuk status fulfillment saat ini.'], 409);
        }

        return redirect()->route('admin.suppliers.purchases')->with('status', 'Tindakan supplier diproses. Periksa status dan bukti sebelum tindakan berikutnya.');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
    }

    private function reauthenticate(Request $request): void
    {
        $confirmed = (int) $request->session()->get('auth.password_confirmed_at', 0);
        if ($confirmed <= 0 || $confirmed > time() || time() - $confirmed > 900) {
            $request->session()->put('url.intended', route('admin.dashboard', ['tab' => 'suppliers']));
            throw new HttpResponseException(response()->json(['message' => 'Konfirmasi password dahulu, lalu masukkan ulang data rahasia.', 'confirmation_url' => route('password.confirm')], 428));
        }
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
        return redirect()->route('admin.dashboard', ['tab' => 'suppliers'])->with('status', $message);
    }
}
