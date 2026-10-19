<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupplierAdminRequest;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPurchase;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Admin\AdminSupplierDashboardData;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierManager;
use App\Services\Suppliers\SupplierOrderHistoryReconciliationService;
use Illuminate\Http\Request;
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
        if ($request->filled('api_key') || $request->input('provider') !== $supplier->provider) {
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
        try {
            $product = $manager->import($catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Katalog berubah. Muat ulang sebelum mengimpor.', 'errors' => $exception->errors()], 409);
        }

        return $this->saved('Draft produk tersedia. Lengkapi detail sebelum dipublikasikan.', $product, 'detail');
    }

    public function map(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));

        return $this->mutate(fn () => $manager->map($product, $catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Pemetaan supplier disimpan.', $product);
    }

    public function move(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));
        try {
            $manager->move($product, $catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Urutan berubah. Muat ulang lalu coba lagi.', 'errors' => $exception->errors()], 409);
            }

            return redirect()->route('admin.products.workspace', ['product' => $product, 'section' => 'supplier'])->withErrors($exception->errors())->with('status', 'Urutan tidak berubah. Muat ulang halaman lalu coba lagi.');
        }

        return $this->saved('Urutan supplier diperbarui.', $product);
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

        return $this->saved('Mapping supplier dilepas. Stok lokal, harga, dan riwayat tetap tersimpan.', $product);
    }

    public function price(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager)
    {
        return $this->mutate(fn () => $manager->price($product, $request->validated(), $request->attributes->get('adminUser'), $request->ip()), 'Kebijakan harga supplier disimpan.', $product, 'price');
    }

    public function preview(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager)
    {
        $item = SupplierCatalogItem::query()->findOrFail($request->integer('catalog_item_id'));
        abort_unless(ProductSupplierSource::query()->where('product_id', $product->id)->where('supplier_catalog_item_id', $item->id)->exists(), 422);

        $input = $request->validated();
        $pricePreview = $manager->preview($product, $item, $input);
        if ($request->expectsJson()) {
            return response()->json($pricePreview);
        }

        $workspace = app(AdminProductWorkspaceData::class)->for($product, includeOptions: true);
        $workspace['policy'] = array_intersect_key($input, array_flip(['version', 'mode', 'markup_basis_points', 'profit_idr', 'minimum_profit_idr', 'rounding_idr']));
        $workspace['price_preview'] = $pricePreview;
        $workspace['preview_catalog_item_id'] = $item->id;
        $workspace['initial_section'] = 'price';

        return view('admin.products.workspace', compact('workspace'));
    }

    public function purchases(Request $request)
    {
        $this->authorizeManager($request);
        $invoice = is_string($request->query('invoice')) ? trim($request->query('invoice')) : '';
        abort_if(strlen($invoice) > 100, 422);
        if ($invoice !== '') {
            $request->session()->put('url.intended', route('admin.suppliers.purchases', ['invoice' => $invoice]));
        }
        $purchases = SupplierPurchase::query()->select(['id', 'supplier_fulfillment_id', 'supplier_id', 'reference', 'supplier_order_id', 'quantity', 'expected_cost_micros', 'actual_cost_micros', 'state', 'last_error', 'created_at', 'updated_at'])
            ->when($invoice !== '', fn ($q) => $q->whereHas('fulfillment.order', fn ($order) => $order->where('invoice_number', $invoice)))
            ->orderByDesc('id')->paginate(25)->withQueryString();
        $fulfillments = SupplierFulfillment::query()->with([
            'currentPurchase.supplier', 'currentPurchase.latestHistoryReconciliation',
            'reservedSupplier', 'manualHandoffs:id,supplier_fulfillment_id,quantity',
            'order:id,customer_id,invoice_number,order_status,payment_status,telegram_receipt_sent_at,expires_at',
            'order.customer:id,telegram_id',
            'orderItem:id,product_name',
        ])
            ->select(['supplier_fulfillments.*'])
            ->when($invoice !== '',
                fn ($q) => $q->whereHas('order', fn ($order) => $order->where('invoice_number', $invoice)),
                fn ($q) => $q->where(fn ($query) => $query->whereNotIn('state', ['delivered', 'cancelled'])
                    ->orWhere('reserved_quantity', '>', 0)
                    ->orWhereHas('purchases', fn ($purchases) => $purchases->whereNotIn('state', ['delivered', 'settled', 'rejected', 'cancelled']))))
            ->orderByDesc('id')->paginate(25, ['*'], 'fulfillments_page')->withQueryString();
        $allocated = ProductStockItem::query()
            ->whereIn('order_item_id', $fulfillments->pluck('order_item_id'))
            ->whereHas('supplierPurchase', fn ($q) => $q->whereNull('warranty_claim_id'))
            ->selectRaw('order_item_id, count(*) as quantity')->groupBy('order_item_id')->pluck('quantity', 'order_item_id');
        foreach ($fulfillments as $fulfillment) {
            $fulfillment->remaining_quantity = max(0, $fulfillment->quantity - $fulfillment->refunded_quantity
                - (int) $fulfillment->manualHandoffs->sum('quantity') - (int) ($allocated[$fulfillment->order_item_id] ?? 0));
            $fulfillment->unpaid_reservation = $fulfillment->isUnpaidReservation();
        }

        return view('admin.suppliers.purchases', compact('purchases', 'fulfillments', 'invoice'));
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
                    $request->filled('purchase_version') ? $request->string('purchase_version')->toString() : null,
                ),
                'history_confirm' => $history->confirmCandidate(
                    (int) $fulfillment->id,
                    $request->attributes->get('adminUser'),
                    $request->integer('history_reconciliation_id'),
                    $request->string('supplier_order_id')->toString(),
                    $request->string('proof_note')->toString(),
                    $request->ip(),
                    $request->integer('purchase_id'),
                    $request->string('purchase_version')->toString(),
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

    private function mutate(callable $mutation, string $message, ?Product $product = null, string $section = 'supplier')
    {
        try {
            $mutation();
        } catch (ValidationException $exception) {
            $conflict = array_key_exists('version', $exception->errors()) || array_key_exists('connection', $exception->errors());

            return response()->json(['message' => $conflict ? 'Konfigurasi berubah atau komitmen supplier masih aktif. Muat ulang.' : 'Pemetaan atau kebijakan tidak valid.', 'errors' => $exception->errors()], $conflict ? 409 : 422);
        }

        return $this->saved($message, $product, $section);
    }

    private function saved(string $message, ?Product $product = null, string $section = 'supplier')
    {
        $url = $product === null ? route('admin.dashboard', ['tab' => 'suppliers']) : route('admin.products.workspace', ['product' => $product, 'section' => $section]);
        if (request()->expectsJson()) {
            // The dashboard supplier form follows redirect_url with a full navigation, so the
            // confirmation survives only as a session flash. Workspace mutations render the
            // JSON message inline and must not leave a flash behind for the next page load.
            if ($product === null) {
                request()->session()->flash('status', $message);
            }

            return response()->json(['message' => $message, 'redirect_url' => $url] + ($product === null ? [] : ['workspace' => app(AdminProductWorkspaceData::class)->for($product)]))->header('Cache-Control', 'no-store, private');
        }

        return redirect()->to($url)->with('status', $message);
    }
}
