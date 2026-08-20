<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductAccountStockRequest;
use App\Models\Product;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeIdempotencyConflict;
use App\Services\ProductStockIntakeSubmission;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class AdminProductAccountStockController extends Controller
{
    public function __invoke(StoreProductAccountStockRequest $request, Product $product, ProductStockIntake $stockIntake): JsonResponse
    {
        try {
            $result = $stockIntake->submit(
                $product,
                ProductStockIntakeSubmission::structured(
                    $request->validated('accounts'),
                    (string) $request->validated('operation_key'),
                ),
                $request->attributes->get('adminUser'),
                $request->ip(),
            );
        } catch (ProductStockIntakeIdempotencyConflict) {
            return response()->json([
                'message' => 'Kunci operasi sudah digunakan untuk pengajuan stok lain.',
                'code' => 'stock_intake_idempotency_conflict',
            ], 409);
        } catch (ValidationException $exception) {
            // Account Input is a JSON-only contract. Returning the safe error
            // bag here also prevents a non-JavaScript client from causing
            // credential drafts to be flashed into the session.
            return response()->json([
                'message' => 'Data Input Akun tidak valid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json($result->toArray(), $result->idempotentReplay ? 200 : 201);
    }
}
