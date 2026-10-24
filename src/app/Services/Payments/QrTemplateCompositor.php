<?php

namespace App\Services\Payments;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Pastes a QR image inside a template's reserved area.
 *
 * The decoded module matrix is rendered at a whole-number pixel scale with a
 * four-module quiet zone, independently of the provider image's pixel size.
 * The reserved square is painted white before the QR is centred. Any failure
 * returns the original QR bytes: decoration must never block a payment.
 */
final class QrTemplateCompositor
{
    private const MAX_IMAGE_SIDE = 1600;

    public function compose(string $qrContents, QrTemplatePlacement $placement): string
    {
        $size = $this->dimensions($qrContents);
        if ($size === null || $size[0] !== $size[1]) {
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

        if ($size[0] > $side) {
            return $qrContents;
        }

        $scaled = $this->normalized($qrContents, $side);

        if ($scaled === null) {
            return $qrContents;
        }

        $targetSide = imagesx($scaled);

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

    private function normalized(string $contents, int $side): ?\GdImage
    {
        try {
            // Preserve the provider's codewords, version, ECC and mask; do not re-encode its payload.
            $matrix = (new QRCode)->readFromBlob($contents)->getQRMatrix()->setQuietZone(4);
            $scale = intdiv($side, $matrix->getSize());
            if ($scale < 1) {
                return null;
            }

            $png = (new QRCode(new QROptions([
                'outputType' => 'png',
                'outputBase64' => false,
                'scale' => $scale,
            ])))->renderMatrix($matrix);

            return $this->create($png);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{0: int, 1: int}|null */
    private function dimensions(string $contents): ?array
    {
        $size = $contents === '' ? false : @getimagesizefromstring($contents);
        if (! is_array($size)
            || $size[0] < 1
            || $size[1] < 1
            || $size[0] > self::MAX_IMAGE_SIDE
            || $size[1] > self::MAX_IMAGE_SIDE) {
            return null;
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * @return \GdImage|resource|null
     */
    private function create(string $contents)
    {
        if ($this->dimensions($contents) === null) {
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
