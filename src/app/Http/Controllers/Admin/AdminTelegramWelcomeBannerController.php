<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateTelegramWelcomeBannerRequest;
use App\Models\StorefrontSetting;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\Admin\ProductImageOptimizer;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AdminTelegramWelcomeBannerController extends Controller
{
    public function __invoke(
        UpdateTelegramWelcomeBannerRequest $request,
        ProductImageOptimizer $images,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');
        $newPath = $images->storeWelcomeBanner($request->file('image'));
        $oldPath = null;

        try {
            [$setting, $oldValues] = DB::transaction(function () use ($newPath, &$oldPath): array {
                $setting = StorefrontSetting::query()
                    ->where('singleton_key', StorefrontSetting::SINGLETON_KEY)
                    ->lockForUpdate()
                    ->first() ?? new StorefrontSetting(['singleton_key' => StorefrontSetting::SINGLETON_KEY]);
                $oldPath = $setting->telegram_welcome_banner_path;
                $oldValues = $setting->exists
                    ? $setting->only([
                        'telegram_welcome_banner_path',
                        'telegram_welcome_banner_file_id',
                        'telegram_welcome_banner_version',
                    ])
                    : [];

                $setting->fill([
                    'telegram_welcome_banner_path' => $newPath,
                    'telegram_welcome_banner_file_id' => null,
                    'telegram_welcome_banner_version' => ((int) $setting->telegram_welcome_banner_version) + 1,
                ]);
                $setting->save();

                return [$setting, $oldValues];
            }, 5);
        } catch (Throwable $exception) {
            $images->deleteManagedImage($newPath);

            throw $exception;
        }

        $auditLogger->log(
            'telegram.welcome_banner.updated',
            $setting,
            $oldValues,
            $setting->only([
                'telegram_welcome_banner_path',
                'telegram_welcome_banner_file_id',
                'telegram_welcome_banner_version',
            ]),
            $user,
            $request->ip(),
        );
        if ($oldPath !== null && $oldPath !== $newPath) {
            $images->deleteManagedImage($oldPath);
        }

        return $redirector->telegramWelcomeBanner()->with('status', 'Telegram Welcome Banner berhasil diperbarui.');
    }
}
