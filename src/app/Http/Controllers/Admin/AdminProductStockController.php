<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreProductStockRequest;
use App\Models\Product;
use App\Services\Admin\AdminProductDashboardFormProtocol;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeIdempotencyConflict;
use App\Services\ProductStockIntakeSubmission;
use App\Support\AdminTelegramDogfoodTiming;
use App\Support\DogfoodTiming;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class AdminProductStockController extends Controller
{
    public function __invoke(StoreProductStockRequest $request, Product $product, ProductStockIntake $stockIntake, AdminProductDashboardFormProtocol $forms): RedirectResponse
    {
        $startedAt = DogfoodTiming::now();
        $user = $request->attributes->get('adminUser');

        $stockItems = $request->stockItems();

        $itemCount = count($stockItems);
        try {
            $stockIntake->submit(
                $product,
                ProductStockIntakeSubmission::legacy($stockItems, $request->operationKey()),
                $user,
                $request->ip(),
            );
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $errors['stock_items'] ??= ['Isi stok tidak valid.'];

            return $forms->stockValidationErrors($request, $product, $errors);
        } catch (ProductStockIntakeIdempotencyConflict) {
            return $forms->stockValidationErrors($request, $product, [
                'stock_items' => ['Kunci operasi sudah digunakan untuk pengajuan stok lain.'],
            ]);
        }
        AdminTelegramDogfoodTiming::logRequestFinished('admin.product_stock_add', $startedAt, ['queued' => true]);

        return $forms->stockAdded($product->fresh(), $itemCount);
    }
}
