<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderProductFamiliesRequest;
use App\Services\Admin\ProductFamilyManagementService;
use Illuminate\Http\JsonResponse;

class AdminProductFamilyReorderController extends Controller
{
    public function __invoke(ReorderProductFamiliesRequest $request, ProductFamilyManagementService $families): JsonResponse
    {
        $ordered = $families->reorder(
            array_map(intval(...), $request->validated('family_ids')),
            array_map(fn (array $family): array => [
                'id' => (int) $family['id'],
                'sort_order' => (int) $family['sort_order'],
            ], $request->validated('expected_order')),
            $request->attributes->get('adminUser'),
            $request->ip(),
        );

        return response()->json([
            'message' => 'Urutan family tersimpan. Katalog Telegram dan toko web memakai urutan ini saat dibuka kembali.',
            'families' => $ordered,
        ])->header('Cache-Control', 'no-store, private');
    }
}
