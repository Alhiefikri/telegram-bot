<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SupplierCatalogItem;
use App\Services\Admin\AdminProductWorkspaceData;
use Illuminate\Http\Request;

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
        $input = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $query = SupplierCatalogItem::query()->select(AdminProductWorkspaceData::CATALOG_COLUMNS)
            ->with(['supplier' => fn ($query) => $query->select(AdminProductWorkspaceData::SUPPLIER_COLUMNS)])
            ->whereDoesntHave('sources', fn ($sources) => $sources->where('product_id', $product->id))
            ->orderBy('name')->orderBy('id');
        if (isset($input['supplier_id'])) {
            $query->where('supplier_id', $input['supplier_id']);
        }
        if (($search = trim($input['q'] ?? '')) !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('external_id', 'like', '%'.$search.'%')->orWhereHas('supplier', fn ($suppliers) => $suppliers->where('name', 'like', '%'.$search.'%')));
        }
        $page = $query->paginate(25);

        return response()->json([
            'items' => $page->getCollection()->map(fn (SupplierCatalogItem $item): array => $data->catalog($item) + ['supplier' => $data->supplier($item), 'cost' => $data->cost($item), 'supplier_version' => $item->supplier->version, 'version' => 0, 'map_url' => route('admin.suppliers.map', $item)])->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), 'per_page' => $page->perPage()],
        ])->header('Cache-Control', 'no-store, private');
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);
    }
}
