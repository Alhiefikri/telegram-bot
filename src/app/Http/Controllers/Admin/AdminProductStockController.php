<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\StructuredStockInputDisabled;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductStockRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeIdempotencyConflict;
use App\Services\ProductStockIntakeSubmission;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminProductStockController extends Controller
{
    public function __invoke(StoreProductStockRequest $request, Product $product, ProductStockIntake $stockIntake, AdminProductDashboardFormProtocol $forms): RedirectResponse|JsonResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        $stockItems = $request->stockItems();

        try {
            $result = $stockIntake->submit(
                $product,
                ProductStockIntakeSubmission::bulk(
                    $stockItems,
                    $request->stockFormat(),
                    $request->operationKey(),
                ),
                $user,
                $request->ip(),
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $errors['stock_items'] ??= ['Isi stok tidak valid.'];

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Isi stok tidak valid.', 'errors' => $errors], 422);
            }

            return $forms->stockValidationErrors($request, $product, $errors);
        } catch (StructuredStockInputDisabled) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Format access token belum tersedia.', 'errors' => ['stock_items' => ['Pemeriksaan kesiapan identitas belum selesai.']]], 422);
            }

            return $forms->stockValidationErrors($request, $product, [
                'stock_items' => ['Format access token belum tersedia karena pemeriksaan kesiapan identitas belum selesai.'],
            ]);
        } catch (ProductStockIntakeIdempotencyConflict) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Kunci operasi sudah digunakan untuk pengajuan stok lain.', 'errors' => ['stock_items' => ['Kunci operasi sudah digunakan untuk pengajuan stok lain.']]], 409);
            }

            return $forms->stockValidationErrors($request, $product, [
                'stock_items' => ['Kunci operasi sudah digunakan untuk pengajuan stok lain.'],
            ]);
        }
        AdminTelegramDogfoodTiming::logRequestFinished('admin.product_stock_add', $startedAt, ['queued' => true]);

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Stok bertambah '.$result->itemCount.' item.', 'workspace' => app(AdminProductWorkspaceData::class)->for($product)])->header('Cache-Control', 'no-store, private');
        }

        return $forms->stockAdded($product->fresh(), $result->itemCount);
    }
}
