<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierCatalogItem;
use App\Services\Admin\AdminProductWorkspaceData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

final class AdminProductWorkspaceController extends Controller
{
    public function __invoke(Request $request, Product $product, AdminProductWorkspaceData $data)
    {
        $this->authorizeManager($request);
        $workspace = $data->for($product, includeOptions: ! $request->expectsJson());

        return $request->expectsJson()
            ? response()->json($workspace)->header('Cache-Control', 'no-store, private')
            : response()->view('admin.products.workspace', compact('workspace'))->header('Cache-Control', 'no-store, private');
    }

    public function catalog(Request $request, Product $product, AdminProductWorkspaceData $data)
    {
        $this->authorizeManager($request);
        $validator = Validator::make($request->query(), ['q' => ['nullable', 'string', 'max:120'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Filter katalog tidak valid.', 'errors' => $validator->errors()], 422)->header('Cache-Control', 'no-store, private');
        }
        $input = $validator->validated();
        $query = SupplierCatalogItem::query()->select(AdminProductWorkspaceData::CATALOG_COLUMNS)
            ->with(['supplier' => fn ($query) => $query->select(AdminProductWorkspaceData::SUPPLIER_COLUMNS)])
            ->whereDoesntHave('sources', fn ($sources) => $sources->where('product_id', $product->id))
            ->orderByRaw('LOWER(name)')->orderBy('id');
        if (isset($input['supplier_id'])) {
            $query->where('supplier_id', $input['supplier_id']);
        }
        if (($search = trim($input['q'] ?? '')) !== '') {
            // Escape the escape character first, then LIKE's wildcard characters.
            // Bind the pattern rather than interpolating user input into SQL.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(fn ($query) => $query
                ->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereRaw("LOWER(external_id) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                ->orWhereHas('supplier', fn ($suppliers) => $suppliers->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])));
        }
        $page = $query->paginate(25);
        $usdRate = SupplierCurrencyRate::query()->whereKey('USD')->value('exchange_rate_idr');
        $suppliers = Supplier::query()->orderByRaw('LOWER(name)')->orderBy('id')->get(['id', 'name', 'is_active']);

        return response()->json([
            'items' => $page->getCollection()->map(fn (SupplierCatalogItem $item): array => array_replace($data->catalog($item), ['currency' => $item->currency ?? $item->supplier->currency]) + ['supplier' => $data->supplier($item), 'cost' => $data->catalogCost($item, $usdRate), 'supplier_version' => $item->supplier->version, 'version' => 0, 'map_url' => route('admin.suppliers.map', $item)])->all(),
            'suppliers' => $suppliers,
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
    }
}
