<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupplierAdminRequest;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Services\Admin\AdminSupplierRedirector;
use App\Services\Admin\SupplierEconomics;
use App\Services\Admin\SupplierOperationConfirmation;
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierCatalogSync;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Suppliers\SupplierManager;
use App\Services\Suppliers\SupplierMoney;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AdminSupplierController extends Controller
{
    public function __construct(
        private readonly AdminSupplierRedirector $redirector,
        private readonly SupplierEconomics $economics,
    ) {}

    public function store(SupplierAdminRequest $request, SupplierManager $manager)
    {
        app(SupplierOperationConfirmation::class)->requireRecent($request);

        try {
            $manager->save(null, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->redirector->tab('Supplier dibuat. Sinkronkan dulu sebelum menjual produknya.');
    }

    public function update(SupplierAdminRequest $request, Supplier $supplier, SupplierManager $manager)
    {
        if ($request->filled('api_key') || $request->input('provider') !== $supplier->provider) {
            app(SupplierOperationConfirmation::class)->requireRecent($request);
        }

        try {
            $manager->save($supplier, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->redirector->tab('Supplier diperbarui. Sinkronkan ulang agar data terbaru dipakai.');
    }

    public function sync(SupplierAdminRequest $request, Supplier $supplier, SupplierCatalogSync $sync)
    {
        // This POST is only a CSRF-protected local action. Provider snapshot is GET-only.
        $applied = $sync->sync($supplier);

        return $this->redirector->fromRequest(
            $request,
            $applied
                ? 'Katalog dan saldo disinkronkan.'
                : 'Supplier belum siap dipakai atau konfigurasinya berubah. Data lama tetap dipakai.'
        );
    }

    public function catalog(Request $request, Supplier $supplier)
    {
        $this->authorizeManager($request);

        $search = trim((string) $request->query('q', ''));
        $search = mb_substr($search, 0, 100);
        $status = (string) $request->query('status', 'semua');
        if (! in_array($status, AdminSupplierRedirector::CATALOG_STATUSES, true)) {
            $status = 'semua';
        }

        $base = SupplierCatalogItem::query()->where('supplier_id', $supplier->id);
        $counts = [
            'semua' => (clone $base)->count(),
            'belum-dijual' => (clone $base)->whereDoesntHave('sources')->count(),
            'sudah-dijual' => (clone $base)->whereHas('sources')->count(),
            'instan' => (clone $base)->where('orderable', true)->where('requires_input', false)->count(),
            'perlu-input' => (clone $base)->where('requires_input', true)->count(),
        ];

        $items = (clone $base)
            ->with(['sources.product'])
            ->when($search !== '', fn ($query) => $query->where(fn ($group) => $group
                ->where('name', 'like', $this->likePattern($search))
                ->orWhere('external_id', 'like', $this->likePattern($search))))
            ->when($status === 'belum-dijual', fn ($query) => $query->whereDoesntHave('sources'))
            ->when($status === 'sudah-dijual', fn ($query) => $query->whereHas('sources'))
            ->when($status === 'instan', fn ($query) => $query->where('orderable', true)->where('requires_input', false))
            ->when($status === 'perlu-input', fn ($query) => $query->where('requires_input', true))
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        $economics = [];
        foreach ($items as $item) {
            $cost = $this->economics->costIdr($item, $supplier);
            $economics[$item->id] = [
                'cost_idr' => $cost,
                'cost_idr_formatted' => $this->economics->formatIdr($cost),
                'cost_currency_formatted' => $this->economics->formatCurrency($item->unit_price_micros, $supplier->currency),
                'suggested_retail_idr' => $cost === null ? null : (int) (ceil($cost * 1.2 / 1000) * 1000),
            ];
        }

        return view('admin.suppliers.catalog', [
            'supplier' => $supplier,
            'items' => $items,
            'filters' => ['q' => $search, 'status' => $status],
            'counts' => $counts,
            'economics' => $economics,
            'passwordConfirmedRecently' => app(SupplierOperationConfirmation::class)->isRecent($request),
        ]);
    }

    public function import(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        try {
            $product = $manager->import($catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->redirector->product($product, 'Produk dibuat sebagai nonaktif. Atur harga lalu aktifkan sumber supplier & tayangkan produk.');
    }

    public function map(SupplierAdminRequest $request, SupplierCatalogItem $catalogItem, ProductSupplierManager $manager)
    {
        $product = Product::query()->findOrFail($request->integer('product_id'));

        try {
            $manager->map($product, $catalogItem, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->redirector->product($product, 'Produk terhubung ke SKU supplier. Periksa harga dan status jualnya.');
    }

    public function product(Request $request, Product $product, ProductSupplierManager $manager)
    {
        $this->authorizeManager($request);

        $sources = ProductSupplierSource::query()
            ->where('product_id', $product->id)
            ->with('catalogItem.supplier')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
        $policy = SupplierPricePolicy::query()->where('product_id', $product->id)->first();
        $product->loadMissing(['category:id,name', 'family:id,name']);

        $primary = $sources->sortBy([['is_active', 'desc'], ['priority', 'asc'], ['id', 'asc']])->first();
        $item = $primary?->catalogItem;
        $supplier = $item?->supplier;
        $policyValues = $this->policyValues($policy);

        $economy = $item instanceof SupplierCatalogItem
            ? $manager->preview($product, $item, $policyValues)
            : null;

        return view('admin.suppliers.product', [
            'product' => $product,
            'supplier' => $supplier,
            'sources' => $sources,
            'primarySource' => $primary,
            'policy' => $policy,
            'policyValues' => $policyValues,
            'familyOptions' => ProductFamily::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'storefrontUrl' => route('storefront.products.show', $product),
            'economy' => $economy === null ? null : [
                ...$economy,
                'cost_formatted' => $this->economics->formatIdr($economy['cost_idr']),
                'retail_formatted' => $this->economics->formatIdr($economy['retail_idr']),
                'profit_formatted' => $this->economics->formatIdr($economy['profit_idr']),
                'profit_percent_formatted' => $this->economics->formatPercent($economy['profit_percent_basis_points']),
                'source_label' => trim(($supplier?->name ?? '').' · '.($item?->name ?? ''), ' ·'),
                'supplier_active' => (bool) ($supplier?->is_active && $supplier?->currency_confirmed),
                'fresh' => $supplier?->sync_expires_at?->isFuture() ?? false,
            ],
            'passwordConfirmedRecently' => app(SupplierOperationConfirmation::class)->isRecent($request),
        ]);
    }

    public function price(SupplierAdminRequest $request, Product $product, ProductSupplierManager $manager)
    {
        try {
            $manager->price($product, $request->validated(), $request->attributes->get('adminUser'), $request->ip());
        } catch (ValidationException $exception) {
            return $this->failed($request, $exception);
        }

        return $this->redirector->product($product, 'Aturan harga disimpan.');
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
        $purchases = SupplierPurchase::query()->select(['id', 'supplier_fulfillment_id', 'supplier_id', 'reference', 'supplier_order_id', 'quantity', 'expected_cost_micros', 'actual_cost_micros', 'exchange_rate_idr', 'state', 'last_error', 'created_at', 'updated_at'])->orderByDesc('id')->paginate(25);
        $supplierNames = Supplier::query()->whereIn('id', $purchases->getCollection()->pluck('supplier_id')->unique()->all())->pluck('name', 'id');
        $purchases->setCollection($purchases->getCollection()->map(fn (SupplierPurchase $purchase): array => [
            'id' => $purchase->id,
            'fulfillment_id' => $purchase->supplier_fulfillment_id,
            'supplier_id' => $purchase->supplier_id,
            'supplier_name' => $supplierNames[$purchase->supplier_id] ?? null,
            'supplier_order_id' => $purchase->supplier_order_id,
            'quantity' => $purchase->quantity,
            'state' => $purchase->state,
            'expected_cost' => $this->formatPurchaseCost($purchase->expected_cost_micros, $purchase->exchange_rate_idr),
            'actual_cost' => $this->formatPurchaseCost($purchase->actual_cost_micros, $purchase->exchange_rate_idr),
            'updated_at' => $purchase->updated_at?->format('d/m/Y H:i'),
        ]));

        $fulfillments = SupplierFulfillment::query()->select(['id', 'order_id', 'order_item_id', 'quantity', 'state', 'last_error', 'created_at'])
            ->where(fn ($query) => $query->whereNotIn('state', ['delivered', 'cancelled'])
                ->orWhereHas('purchases', fn ($purchases) => $purchases->whereNotIn('state', ['delivered', 'settled', 'rejected', 'cancelled'])))
            ->orderByDesc('id')->paginate(25, ['*'], 'fulfillments_page');

        return view('admin.suppliers.purchases', [
            'purchases' => $purchases,
            'fulfillments' => $fulfillments,
            'passwordConfirmedRecently' => app(SupplierOperationConfirmation::class)->isRecent($request),
        ]);
    }

    public function operate(SupplierAdminRequest $request, SupplierFulfillment $fulfillment, SupplierFulfillmentService $service)
    {
        // The action form only exists on the purchase monitor; default there for
        // callers that do not send an explicit context.
        if (! $request->filled('context')) {
            $request->merge(['context' => 'purchases']);
        }

        app(SupplierOperationConfirmation::class)->requireRecent($request);
        try {
            match ($request->input('action')) {
                'retry' => $service->retryAfterTopup((int) $fulfillment->id),
                'recover' => $service->recoverOrder((int) $fulfillment->id, $request->string('supplier_order_id')->toString()),
                'manual' => $service->provideManualCredentials((int) $fulfillment->id, array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $request->string('credentials')->toString())), fn (string $value): bool => $value !== ''))),
            };
        } catch (InvalidOrderTransition|InsufficientStock|\DomainException|\InvalidArgumentException|ValidationException $exception) {
            // Operational errors may contain delivery data: never echo them.
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Tindakan tidak aman untuk status fulfillment saat ini.'], 409);
            }

            return $this->redirector->fromRequest($request, null, [
                'action' => ['Tindakan tidak aman untuk status fulfillment saat ini. Muat ulang halaman, lalu pilih tindakan yang sesuai.'],
            ]);
        }

        return $this->redirector->fromRequest($request, 'Tindakan supplier diproses. Periksa status dan bukti sebelum tindakan berikutnya.');
    }

    /**
     * @return array<string, mixed>
     */
    private function policyValues(?SupplierPricePolicy $policy): array
    {
        return [
            'mode' => $policy?->mode ?? 'manual',
            'markup_basis_points' => (int) ($policy?->markup_basis_points ?? 0),
            'profit_idr' => (int) ($policy?->profit_idr ?? 0),
            'minimum_profit_idr' => (int) ($policy?->minimum_profit_idr ?? 0),
            'buffer_idr' => (int) ($policy?->buffer_idr ?? 0),
            'rounding_idr' => (int) ($policy?->rounding_idr ?? 1),
        ];
    }

    private function likePattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }

    private function formatPurchaseCost(?int $micros, ?int $rate): ?string
    {
        if ($micros === null || $rate === null || $rate <= 0) {
            return null;
        }

        try {
            return $this->economics->formatIdr(SupplierMoney::costIdr($micros, $rate));
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
    }

    private function failed(SupplierAdminRequest $request, ValidationException $exception)
    {
        $errors = $exception->errors();
        $conflict = array_key_exists('version', $errors) || array_key_exists('connection', $errors);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $conflict ? 'Konfigurasi berubah atau komitmen supplier masih aktif. Muat ulang.' : 'Pemetaan atau kebijakan tidak valid.',
                'errors' => $errors,
            ], $conflict ? 409 : 422);
        }

        if ($conflict) {
            $errors['version'] = ['Data sudah berubah di tempat lain. Muat ulang halaman lalu coba lagi.'];
        }

        return $this->redirector->fromRequest($request, null, $errors);
    }
}
