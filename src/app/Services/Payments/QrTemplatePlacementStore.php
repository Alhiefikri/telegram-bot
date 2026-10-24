<?php

namespace App\Services\Payments;

use App\Models\PaymentQrTemplateSetting;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolves the current template for each presentation, including retained queue rails.
 *
 * A missing row, missing file, unreadable image, or out-of-bounds geometry
 * resolves to null, which keeps the payment QR exactly as the provider or the
 * local renderer produced it.
 */
final class QrTemplatePlacementStore
{
    public function current(): ?QrTemplatePlacement
    {
        $setting = PaymentQrTemplateSetting::current();
        if ($setting === null || ! $setting->enabled || ! $setting->isConfigured()) {
            return null;
        }

        $path = (string) $setting->image_path;

        try {
            $disk = Storage::disk('public');
            if (! $disk->exists($path)) {
                return null;
            }

            $contents = $disk->get($path);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($contents) || $contents === '') {
            return null;
        }

        $size = @getimagesizefromstring($contents);
        if (! is_array($size)) {
            return null;
        }

        [$imageWidth, $imageHeight] = $size;
        $x = (int) $setting->qr_x;
        $y = (int) $setting->qr_y;
        $width = (int) $setting->qr_width;
        $height = (int) $setting->qr_height;

        if ($width < 1
            || $height < 1
            || $x < 0
            || $y < 0
            || $x + $width > $imageWidth
            || $y + $height > $imageHeight) {
            return null;
        }

        return new QrTemplatePlacement($contents, $x, $y, $width, $height);
    }
}
