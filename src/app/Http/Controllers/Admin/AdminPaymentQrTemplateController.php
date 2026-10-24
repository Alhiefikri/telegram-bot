<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePaymentQrTemplateRequest;
use App\Models\PaymentQrTemplateSetting;
use App\Models\User;
use App\Services\Admin\AdminDashboardRedirector;
use App\Services\AuditLogger;
use App\Services\Payments\QrTemplateInspector;
use App\Services\Payments\QrTemplateStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class AdminPaymentQrTemplateController extends Controller
{
    private const MIN_WHITE_COVERAGE = 0.90;

    public function update(
        UpdatePaymentQrTemplateRequest $request,
        QrTemplateStorage $templates,
        QrTemplateInspector $inspector,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');
        $validated = $request->validated();

        $setting = PaymentQrTemplateSetting::query()
            ->where('singleton_key', PaymentQrTemplateSetting::SINGLETON_KEY)
            ->firstOrFail();
        $expectedVersion = $setting->version;
        $expectedPath = $setting->image_path;
        $newPath = $request->hasFile('image')
            ? $templates->store($request->file('image'))
            : null;
        $candidatePath = $newPath ?? $expectedPath;

        $failure = $this->validateCandidate(
            $inspector,
            $candidatePath,
            (int) $validated['qr_x'],
            (int) $validated['qr_y'],
            (int) $validated['qr_width'],
            (int) $validated['qr_height'],
        );

        if ($failure !== null) {
            if ($newPath !== null) {
                $templates->delete($newPath);
            }

            return $redirector->paymentQrTemplate()->withErrors(['image' => $failure], 'qrTemplate');
        }

        try {
            $result = DB::transaction(function () use ($validated, $candidatePath, $expectedVersion, $expectedPath): ?array {
                $locked = PaymentQrTemplateSetting::query()
                    ->where('singleton_key', PaymentQrTemplateSetting::SINGLETON_KEY)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($locked->version !== $expectedVersion || $locked->image_path !== $expectedPath) {
                    return null;
                }

                $oldValues = $locked->only([
                    'image_path',
                    'qr_x',
                    'qr_y',
                    'qr_width',
                    'qr_height',
                    'version',
                ]);

                $locked->fill([
                    'image_path' => $candidatePath,
                    'qr_x' => (int) $validated['qr_x'],
                    'qr_y' => (int) $validated['qr_y'],
                    'qr_width' => (int) $validated['qr_width'],
                    'qr_height' => (int) $validated['qr_height'],
                    'version' => ((int) ($locked->version ?? 0)) + 1,
                ])->save();

                return [$locked, $oldValues];
            }, 5);
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $templates->delete($newPath);
            }

            throw $exception;
        }

        if ($result === null) {
            if ($newPath !== null) {
                $templates->delete($newPath);
            }

            return $redirector->paymentQrTemplate()->withErrors([
                'image' => 'Template QR telah diubah oleh permintaan lain. Muat ulang halaman lalu coba lagi.',
            ], 'qrTemplate');
        }

        [$setting, $oldValues] = $result;

        $auditLogger->log(
            'payment.qr_template.updated',
            $setting,
            $oldValues,
            $setting->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height', 'version']),
            $user instanceof User ? $user : null,
            $request->ip(),
        );

        $oldPath = $oldValues['image_path'];
        if ($oldPath !== null && $oldPath !== $candidatePath) {
            $templates->delete($oldPath);
        }

        return $redirector->paymentQrTemplate()->with('status', 'Template QR pembayaran berhasil disimpan.');
    }

    public function status(
        Request $request,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');
        if (! $user?->role?->canManagePaymentSettings()) {
            abort(403);
        }

        $validator = validator($request->all(), ['enabled' => ['required', 'boolean']]);
        if ($validator->fails()) {
            return $redirector->paymentQrTemplate()->withErrors($validator, 'qrTemplate');
        }
        $enabled = $request->boolean('enabled');

        $result = DB::transaction(function () use ($enabled): ?array {
            $locked = PaymentQrTemplateSetting::query()
                ->where('singleton_key', PaymentQrTemplateSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->firstOrFail();
            if ($enabled && (! $locked->isConfigured() || ! Storage::disk('public')->exists($locked->image_path))) {
                return null;
            }

            $oldValues = $locked->only(['enabled', 'version']);
            if ($locked->enabled !== $enabled) {
                $locked->forceFill([
                    'enabled' => $enabled,
                    'version' => $locked->version + 1,
                ])->save();
            }

            return [$locked, $oldValues];
        }, 5);

        if ($result === null) {
            return $redirector->paymentQrTemplate()->withErrors([
                'enabled' => 'Simpan gambar dan area QR yang valid sebelum mengaktifkan template.',
            ], 'qrTemplate');
        }

        [$setting, $oldValues] = $result;
        if ($oldValues['enabled'] !== $setting->enabled) {
            $auditLogger->log(
                $enabled ? 'payment.qr_template.enabled' : 'payment.qr_template.disabled',
                $setting,
                $oldValues,
                $setting->only(['enabled', 'version']),
                $user instanceof User ? $user : null,
                $request->ip(),
            );
        }

        return $redirector->paymentQrTemplate()->with('status', $enabled
            ? 'Template QR diaktifkan untuk pengiriman berikutnya.'
            : 'Template QR dinonaktifkan. QR asli digunakan; gambar dan koordinat tetap tersimpan.');
    }

    public function destroy(
        Request $request,
        QrTemplateStorage $templates,
        AuditLogger $auditLogger,
        AdminDashboardRedirector $redirector,
    ): RedirectResponse {
        $user = $request->attributes->get('adminUser');
        if (! $user?->role?->canManagePaymentSettings()) {
            abort(403);
        }

        $result = DB::transaction(function (): ?array {
            $locked = PaymentQrTemplateSetting::query()
                ->where('singleton_key', PaymentQrTemplateSetting::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            $oldValues = $locked->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height', 'version']);

            $locked->forceFill([
                'enabled' => false,
                'image_path' => null,
                'qr_x' => null,
                'qr_y' => null,
                'qr_width' => null,
                'qr_height' => null,
                'version' => ((int) $locked->version) + 1,
            ])->save();

            return [$locked, $oldValues];
        }, 5);

        if ($result === null) {
            return $redirector->paymentQrTemplate()->with('status', 'Template QR pembayaran sudah nonaktif.');
        }

        [$setting, $oldValues] = $result;

        $auditLogger->log(
            'payment.qr_template.removed',
            $setting,
            $oldValues,
            $setting->only(['image_path', 'qr_x', 'qr_y', 'qr_width', 'qr_height', 'version']),
            $user instanceof User ? $user : null,
            $request->ip(),
        );

        $oldPath = $oldValues['image_path'];
        if ($oldPath !== null) {
            $templates->delete($oldPath);
        }

        return $redirector->paymentQrTemplate()->with('status', 'Template QR pembayaran dinonaktifkan. QR tampil seperti sebelumnya.');
    }

    private function validateCandidate(
        QrTemplateInspector $inspector,
        ?string $path,
        int $x,
        int $y,
        int $width,
        int $height,
    ): ?string {
        if ($path === null || $path === '') {
            return 'Pilih gambar template QR terlebih dahulu.';
        }

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return 'Gambar template QR tidak ditemukan. Upload ulang.';
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return 'Gambar template QR tidak bisa dibaca. Upload ulang.';
        }

        if (! is_string($contents) || $contents === '') {
            return 'Gambar template QR tidak bisa dibaca. Upload ulang.';
        }

        $dimensions = $inspector->dimensions($contents);
        if ($dimensions === null) {
            return 'Gambar template QR tidak bisa dibaca sebagai gambar.';
        }

        if ($x + $width > $dimensions['width'] || $y + $height > $dimensions['height']) {
            return 'Area QR berada di luar gambar template ('.$dimensions['width'].' x '.$dimensions['height'].' piksel).';
        }

        $coverage = $inspector->nearWhiteCoverage($contents, $x, $y, $width, $height);
        if ($coverage === null) {
            return 'Area QR tidak bisa diperiksa. Periksa koordinat dan ukuran.';
        }

        if ($coverage < self::MIN_WHITE_COVERAGE) {
            return 'Area QR harus berada di bagian putih polos (minimal 90% putih). Area saat ini hanya '
                .number_format($coverage * 100, 1, ',', '.').'% putih.';
        }

        return null;
    }
}
