<?php

namespace Tests\Unit\Services\Payments;

use App\Services\Payments\QrTemplateCompositor;
use App\Services\Payments\QrTemplatePlacement;
use chillerlan\QRCode\QRCode;
use PHPUnit\Framework\TestCase;

class QrTemplateCompositorTest extends TestCase
{
    private const PAYLOAD = '00020101021226670016COM.NOBUBANK.WWW01189360050300000898250214MERCHANT123450303UMI51440014ID.CO.QRIS.WWW0215ID20212345678900303UMI5204599953033605802ID5910TOKO TES6013JAKARTA PUSAT61051017062070703A016304ABCD';

    public function test_composites_provider_sized_qr_into_centred_square(): void
    {
        $qr = $this->fixture('provider-qr-285.png');
        $template = $this->fixture('template-rect-box.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($template, 240, 220, 560, 520),
        );

        $this->assertSame([1000, 1000], $this->dimensions($output));
        $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output));
        $this->assertSame(245, $this->darkWidth($output, 260, 220, 520, 520));
    }

    public function test_enlarges_only_by_whole_factors_with_nearest_neighbour(): void
    {
        $qr = $this->fixture('provider-qr-285.png');
        $template = $this->fixture('template-square-box.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($template, 250, 250, 600, 600),
        );

        $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output));
        $this->assertSame([305, 305], $this->darkOrigin($output, 250, 250, 600, 600));
        $this->assertSame(490, $this->darkWidth($output, 250, 250, 600, 600));
        $this->assertSame(
            $this->qrPixels($this->nearestScaled($qr, 570)),
            $this->qrPixels($output, 265, 265, 570),
            'Enlargement must stay pixel-identical to nearest-neighbour scaling.',
        );
    }

    public function test_rectangular_reserved_area_uses_a_centred_square(): void
    {
        $qr = $this->fixture('short-qr-165.png');
        $template = $this->fixture('template-rect-box.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($template, 240, 220, 560, 420),
        );

        $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output));
        $this->assertSame([395, 305], $this->darkOrigin($output, 240, 220, 560, 420));
        $this->assertSame(250, $this->darkWidth($output, 240, 220, 560, 420));
    }

    public function test_returns_original_bytes_when_no_template_is_configured(): void
    {
        $qr = $this->fixture('provider-qr-285.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement('', 250, 250, 520, 520),
        );

        $this->assertSame($qr, $output);
    }

    public function test_returns_original_bytes_for_corrupt_qr_image(): void
    {
        $corrupt = $this->fixture('corrupt.bin');

        $output = (new QrTemplateCompositor)->compose(
            $corrupt,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 250, 250, 520, 520),
        );

        $this->assertSame($corrupt, $output);
    }

    public function test_returns_original_bytes_when_qr_is_not_square(): void
    {
        $qr = $this->fixture('non-square-300x200.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 250, 250, 520, 520),
        );

        $this->assertSame($qr, $output);
    }

    public function test_returns_original_bytes_when_reserved_area_exceeds_template(): void
    {
        $qr = $this->fixture('provider-qr-285.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 700, 700, 520, 520),
        );

        $this->assertSame($qr, $output);
    }

    public function test_returns_original_bytes_instead_of_shrinking_a_larger_qr(): void
    {
        $qr = $this->fixture('provider-qr-285.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 250, 250, 200, 200),
        );

        $this->assertSame($qr, $output);
    }

    public function test_accepts_webp_template(): void
    {
        $qr = $this->fixture('provider-qr-285.png');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($this->fixture('template-square-box.webp'), 250, 250, 520, 520),
        );

        $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output));
    }

    public function test_paints_the_reserved_square_white_before_pasting(): void
    {
        $qr = $this->fixture('provider-qr-285.png');
        $noisy = $this->noisyTemplate();

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($noisy, 250, 250, 520, 520),
        );

        $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output));
        $image = imagecreatefromstring($output);
        $corner = imagecolorsforindex($image, imagecolorat($image, 252, 252));
        $this->assertSame([255, 255, 255], [$corner['red'], $corner['green'], $corner['blue']]);
        $this->assertSame(0, $corner['alpha']);
    }

    public function test_flattens_template_transparency_to_opaque_white(): void
    {
        $template = imagecreatetruecolor(400, 400);
        imagesavealpha($template, true);
        imagealphablending($template, false);
        imagefilledrectangle($template, 0, 0, 399, 399, imagecolorallocatealpha($template, 0, 0, 0, 127));
        imagefilledrectangle($template, 80, 80, 319, 319, imagecolorallocate($template, 255, 255, 255));
        ob_start();
        imagepng($template);
        $templatePng = (string) ob_get_clean();
        imagedestroy($template);

        $output = (new QrTemplateCompositor)->compose(
            $this->fixture('short-qr-165.png'),
            new QrTemplatePlacement($templatePng, 80, 80, 240, 240),
        );

        $image = imagecreatefromstring($output);
        $outside = imagecolorsforindex($image, imagecolorat($image, 5, 5));
        $this->assertSame([255, 255, 255], [$outside['red'], $outside['green'], $outside['blue']]);
        $this->assertSame(0, $outside['alpha']);
        $this->assertSame($this->fixture('short-payload.txt'), $this->decodedPayload($output));
    }

    public function test_composite_survives_jpeg_recompression_at_chat_sizes(): void
    {
        $qr = $this->fixture('provider-qr-285.png');
        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 250, 250, 520, 520),
        );
        $expected = $this->decodedPayload($qr);
        $source = imagecreatefromstring($output);

        foreach ([1000, 800, 640] as $size) {
            $resized = imagescale($source, $size, $size, IMG_BILINEAR_FIXED);
            ob_start();
            imagejpeg($resized, null, 75);
            $jpeg = (string) ob_get_clean();
            imagedestroy($resized);

            $decoded = @(new QRCode)->readFromBlob($jpeg)->data;
            $this->assertSame($expected, $decoded, 'Composite must decode at '.$size.'px JPEG q75.');
        }

        imagedestroy($source);
    }

    public function test_composite_of_every_fixture_qr_decodes_after_compositing(): void
    {
        $template = $this->fixture('template-square-box.png');

        foreach (['provider-qr-285.png', 'local-render-424.png', 'short-qr-165.png'] as $fixture) {
            $qr = $this->fixture($fixture);
            $output = (new QrTemplateCompositor)->compose(
                $qr,
                new QrTemplatePlacement($template, 250, 250, 520, 520),
            );

            $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output), $fixture);
        }
    }

    public function test_decodes_jpeg_provider_image_into_png_composite(): void
    {
        $qr = $this->fixture('provider-qr-285.jpg');

        $output = (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 250, 250, 520, 520),
        );

        $this->assertStringStartsWith("\x89PNG\r\n\x1A\n", $output);
        $this->assertSame($this->decodedPayload($qr), $this->decodedPayload($output));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../../../Fixtures/qr/'.$name);
        $this->assertIsString($contents);

        return $contents;
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $png): array
    {
        $size = getimagesizefromstring($png);
        $this->assertIsArray($size);

        return [(int) $size[0], (int) $size[1]];
    }

    private function decodedPayload(string $imageContents): string
    {
        $decoded = (new QRCode)->readFromBlob($imageContents);

        return $decoded->data;
    }

    /**
     * Width of the dark QR area inside the given square, including its own
     * quiet zone. A provider QR of 285 px has 245 dark pixels.
     */
    private function darkWidth(string $png, int $boxX, int $boxY, int $boxW, int $boxH): int
    {
        $image = imagecreatefromstring($png);
        $minX = PHP_INT_MAX;
        $maxX = -1;

        for ($y = $boxY; $y < $boxY + $boxH; $y++) {
            for ($x = $boxX; $x < $boxX + $boxW; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $luma = 0.299 * $color['red'] + 0.587 * $color['green'] + 0.114 * $color['blue'];
                if ($luma < 128) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                }
            }
        }

        return $maxX - $minX + 1;
    }

    private function nearestScaled(string $png, int $side): string
    {
        $image = imagescale(imagecreatefromstring($png), $side, $side, IMG_NEAREST_NEIGHBOUR);
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    /** @return list<string> */
    private function qrPixels(string $png, int $x = 0, int $y = 0, ?int $side = null): array
    {
        $image = imagecreatefromstring($png);
        $side ??= imagesx($image);
        $pixels = [];

        for ($dy = 0; $dy < $side; $dy++) {
            for ($dx = 0; $dx < $side; $dx++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x + $dx, $y + $dy));
                $pixels[] = $color['red'].','.$color['green'].','.$color['blue'];
            }
        }

        imagedestroy($image);

        return $pixels;
    }

    /** @return array{0: int, 1: int} */
    private function darkOrigin(string $png, int $boxX, int $boxY, int $boxW, int $boxH): array
    {
        $image = imagecreatefromstring($png);
        $minX = PHP_INT_MAX;
        $minY = PHP_INT_MAX;

        for ($y = $boxY; $y < $boxY + $boxH; $y++) {
            for ($x = $boxX; $x < $boxX + $boxW; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $luma = 0.299 * $color['red'] + 0.587 * $color['green'] + 0.114 * $color['blue'];
                if ($luma < 128) {
                    $minX = min($minX, $x);
                    $minY = min($minY, $y);
                }
            }
        }

        imagedestroy($image);

        return [$minX, $minY];
    }

    private function noisyTemplate(): string
    {
        $image = imagecreatetruecolor(1000, 1000);
        imagefilledrectangle($image, 0, 0, 999, 999, imagecolorallocate($image, 30, 140, 220));
        for ($x = 250; $x < 770; $x += 10) {
            imagefilledrectangle($image, $x, 250, $x + 4, 769, imagecolorallocate($image, 200, 30, 30));
        }
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }
}
