<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateStorefrontPlaceholderRequest;
use App\Models\StorefrontSetting;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

class AdminStorefrontPlaceholderController extends Controller
{
    public function __invoke(
        UpdateStorefrontPlaceholderRequest $request,
        ProductImageOptimizer $images,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');
        $newPath = $images->storePlaceholder($request->file('image'));
        $oldPath = null;

        try {
            [$setting, $oldValues] = DB::transaction(function () use ($newPath, &$oldPath): array {
                $setting = StorefrontSetting::query()
                    ->where('singleton_key', StorefrontSetting::SINGLETON_KEY)
                    ->lockForUpdate()
                    ->first() ?? new StorefrontSetting(['singleton_key' => StorefrontSetting::SINGLETON_KEY]);
                $oldPath = $setting->product_placeholder_image_path;
                $oldValues = $setting->exists
                    ? $setting->only(['product_placeholder_image_path'])
                    : [];

                $setting->product_placeholder_image_path = $newPath;
                $setting->save();

                return [$setting, $oldValues];
            }, 5);
        } catch (Throwable $exception) {
            $images->deleteManagedImage($newPath);

            throw $exception;
        }

        $auditLogger->log(
            'storefront.product_placeholder.updated',
            $setting,
            $oldValues,
            $setting->only(['product_placeholder_image_path']),
            $user,
            $request->ip(),
        );
        $images->deleteManagedImage($oldPath);

        return $redirector->products()->with('status', 'Gambar default katalog berhasil diperbarui.');
    }
}
