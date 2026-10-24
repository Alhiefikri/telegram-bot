<?php

namespace App\Services\Payments;

/**
 * Pastes a QR image inside a template's reserved area.
 *
 * The QR is only ever enlarged by a whole-number factor and resampled with
 * nearest-neighbour, so QR modules stay pixel-aligned. The reserved square is
 * painted white first, which keeps the QR quiet zone clean even when the
 * template was stored with lossy compression. Every failure path returns the
 * original QR bytes: decoration must never block a payment.
 */
final class QrTemplateCompositor
{
    private const MAX_IMAGE_SIDE = 1600;

    public function compose(string $qrContents, QrTemplatePlacement $placement): string
    {
        $qr = $this->create($qrContents);

        if ($qr === null) {
            return $qrContents;
        }

        $qrSide = imagesx($qr);
        if ($qrSide < 1 || imagesy($qr) !== $qrSide) {
            return $qrContents;
        }

        $template = $this->create($placement->templateContents);
        if ($template === null) {
            return $qrContents;
        }

        $canvasWidth = imagesx($template);
        $canvasHeight = imagesy($template);
        $side = $placement->squareSide();
        $squareX = $placement->squareX();
        $squareY = $placement->squareY();

        if ($side < 1
            || $squareX < 0
            || $squareY < 0
            || $squareX + $side > $canvasWidth
            || $squareY + $side > $canvasHeight) {
            return $qrContents;
        }

        $factor = intdiv($side, $qrSide);
        if ($factor < 1) {
            return $qrContents;
        }

        $targetSide = $qrSide * $factor;
        $scaled = $factor === 1
            ? $qr
            : imagescale($qr, $targetSide, $targetSide, IMG_NEAREST_NEIGHBOUR);

        if ($scaled === false) {
            return $qrContents;
        }

        $canvas = $this->flatten($template);
        imagefilledrectangle(
            $canvas,
            $squareX,
            $squareY,
            $squareX + $side - 1,
            $squareY + $side - 1,
            imagecolorallocate($canvas, 255, 255, 255),
        );
        imagecopy(
            $canvas,
            $scaled,
            $squareX + intdiv($side - $targetSide, 2),
            $squareY + intdiv($side - $targetSide, 2),
            0,
            0,
            $targetSide,
            $targetSide,
        );

        return $this->png($canvas) ?? $qrContents;
    }

    /**
     * @return \GdImage|resource|null
     */
    private function create(string $contents)
    {
        $size = $contents === '' ? false : @getimagesizefromstring($contents);
        if (! is_array($size)
            || $size[0] < 1
            || $size[1] < 1
            || $size[0] > self::MAX_IMAGE_SIDE
            || $size[1] > self::MAX_IMAGE_SIDE) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        return $image === false ? null : $image;
    }

    /**
     * Flatten template transparency onto white so the delivered image is
     * always opaque and every pixel behind the QR is predictable.
     *
     * @param  \GdImage|resource  $template
     * @return \GdImage|resource
     */
    private function flatten($template)
    {
        $width = imagesx($template);
        $height = imagesy($template);
        $canvas = imagecreatetruecolor($width, $height);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, imagecolorallocate($canvas, 255, 255, 255));
        imagealphablending($canvas, true);
        imagecopy($canvas, $template, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /**
     * @param  \GdImage|resource  $image
     */
    private function png($image): ?string
    {
        ob_start();
        $encoded = imagepng($image);
        $contents = ob_get_clean();

        return $encoded && is_string($contents) && $contents !== '' ? $contents : null;
    }
}
