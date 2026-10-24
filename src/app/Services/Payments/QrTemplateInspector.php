<?php

namespace App\Services\Payments;

/**
 * Read-only checks used when the owner configures a QR template from the
 * Admin Dashboard. The same rules the runtime uses are validated here, so a
 * misconfigured area is rejected before it can reach a customer.
 */
final class QrTemplateInspector
{
    private const WHITE_THRESHOLD = 245;

    /** @return array{width: int, height: int}|null */
    public function dimensions(string $contents): ?array
    {
        $size = @getimagesizefromstring($contents);

        return is_array($size) ? ['width' => (int) $size[0], 'height' => (int) $size[1]] : null;
    }

    /**
     * Fraction of pixels in the reserved area that are near-white. A QR must
     * sit on a clean background, so an area placed over artwork is rejected.
     */
    public function nearWhiteCoverage(string $contents, int $x, int $y, int $width, int $height): ?float
    {
        $image = @imagecreatefromstring($contents);
        if ($image === false) {
            return null;
        }

        $imageWidth = imagesx($image);
        $imageHeight = imagesy($image);
        if ($width < 1
            || $height < 1
            || $x < 0
            || $y < 0
            || $x + $width > $imageWidth
            || $y + $height > $imageHeight) {
            return null;
        }

        $white = 0;
        $total = $width * $height;
        $stride = max(1, (int) ceil(sqrt($total / 250_000)));

        for ($dy = 0; $dy < $height; $dy += $stride) {
            for ($dx = 0; $dx < $width; $dx += $stride) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x + $dx, $y + $dy));
                if ($color['alpha'] < 64
                    && $color['red'] >= self::WHITE_THRESHOLD
                    && $color['green'] >= self::WHITE_THRESHOLD
                    && $color['blue'] >= self::WHITE_THRESHOLD) {
                    $white++;
                }
            }
        }

        $samples = (int) (ceil($width / $stride) * ceil($height / $stride));

        return $samples === 0 ? null : $white / $samples;
    }
}
