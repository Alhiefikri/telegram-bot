<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminProductSearchController extends Controller
{
    private const INCLUDABLE_FIELDS = ['description'];

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->attributes->get('adminUser')?->role?->canManageStoreOperations() === true, 403);

        $search = trim((string) $request->query('q', ''));
        $search = mb_substr($search, 0, 100);

        if (mb_strlen($search) < 2) {
            return response()->json(['products' => []]);
        }

        $include = collect(explode(',', (string) $request->query('include', '')))
            ->map(fn (string $field): string => trim($field))
            ->intersect(self::INCLUDABLE_FIELDS)
            ->values()
            ->all();
        $withDescription = in_array('description', $include, true);

        $products = Product::query()
            ->where(fn ($group) => $group
                ->where('name', 'like', $this->likePattern($search))
                ->orWhere('id', (int) $search))
            ->orderBy('name')
            ->limit(20)
            ->get($withDescription
                ? ['id', 'name', 'price_amount', 'is_active', 'description']
                : ['id', 'name', 'price_amount', 'is_active']);

        return response()->json([
            'products' => $products->map(function (Product $product) use ($withDescription): array {
                $payload = [
                    'id' => $product->id,
                    'name' => $product->name,
                    'price_amount' => (int) $product->price_amount,
                    'price' => 'Rp'.number_format((int) $product->price_amount, 0, ',', '.'),
                    'is_active' => (bool) $product->is_active,
                ];
                if ($withDescription) {
                    $payload['description'] = (string) $product->description;
                }

                return $payload;
            })->all(),
        ]);
    }

    private function likePattern(string $search): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
    }
}
