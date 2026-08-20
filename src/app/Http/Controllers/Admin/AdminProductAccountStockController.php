<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StructuredStockInputDisabled;
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
    public function review(StoreProductAccountStockRequest $request, Product $product, ProductStockIntake $stockIntake): JsonResponse
    {
        if (! $stockIntake->structuredInputEnabled()) {
            return $this->structuredInputDisabledResponse();
        }

        try {
            $review = $stockIntake->review(
                $product,
                ProductStockIntakeSubmission::structured(
                    $request->validated('accounts'),
                    (string) $request->validated('operation_key'),
                ),
                $request->attributes->get('adminUser'),
            );
        } catch (StructuredStockInputDisabled) {
            return $this->structuredInputDisabledResponse();
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'Data Input Akun tidak valid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $payload = $review->toArray();

        return response()->json([
            'message' => $review->status === 'duplicate'
                ? 'Review server menemukan konflik identitas. Edit akun yang ditandai sebelum menyimpan.'
                : 'Review server selesai.',
            'review' => $payload,
            // Keep the top-level fields convenient for lightweight clients;
            // both shapes contain only credential-free review metadata.
            ...$payload,
        ]);
    }

    public function __invoke(StoreProductAccountStockRequest $request, Product $product, ProductStockIntake $stockIntake): JsonResponse
    {
        if (! $stockIntake->structuredInputEnabled()) {
            return $this->structuredInputDisabledResponse();
        }

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
        } catch (StructuredStockInputDisabled) {
            return $this->structuredInputDisabledResponse();
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

        return response()->json([
            ...$result->toArray(),
            'redirect_url' => route('admin.dashboard', ['tab' => 'produk', 'stock_added' => $result->itemCount, 'stock_product' => $product->id]).'#product-'.$product->id,
            'message' => "Stok bertambah {$result->itemCount} akun.",
        ], $result->idempotentReplay ? 200 : 201);
    }

    private function structuredInputDisabledResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Input Akun sedang tidak tersedia. Silakan hubungi administrator.',
            'code' => 'structured_stock_input_disabled',
        ], 503);
    }
}
