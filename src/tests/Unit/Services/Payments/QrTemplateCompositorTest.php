<?php

namespace Tests\Unit\Services\Payments;

use App\Services\Payments\QrTemplateCompositor;
use App\Services\Payments\QrTemplatePlacement;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PHPUnit\Framework\TestCase;

class QrTemplateCompositorTest extends TestCase
{
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

    public function test_oversized_template_falls_back_without_compositing(): void
    {
        $image = imagecreatetruecolor(1601, 1601);
        imagefilledrectangle($image, 0, 0, 1600, 1600, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $template = (string) ob_get_clean();
        $qr = $this->fixture('provider-qr-285.png');

        $this->assertSame($qr, (new QrTemplateCompositor)->compose(
            $qr,
            new QrTemplatePlacement($template, 250, 250, 520, 520),
        ));
    }

    public function test_provider_400px_qr_fills_its_reserved_area_and_decodes_in_chat(): void
    {
        $payload = $this->fixture('provider-payload.txt');
        $qr = (new QRCode(new QROptions([
            'outputType' => 'png',
            'outputBase64' => false,
            'version' => 9,
            'scale' => 7,
            'quietzoneSize' => 2,
        ])))->render($payload);
        $image = imagescale(imagecreatefromstring($qr), 400, 400, IMG_NEAREST_NEIGHBOUR);
        ob_start();
        imagepng($image);
        $provider = (string) ob_get_clean();

        $template = imagecreatetruecolor(1254, 1254);
        imagefilledrectangle($template, 0, 0, 1253, 1253, imagecolorallocate($template, 255, 255, 255));
        ob_start();
        imagepng($template);
        $output = (new QrTemplateCompositor)->compose(
            $provider,
            new QrTemplatePlacement((string) ob_get_clean(), 490, 430, 690, 640),
        );
        $rendered = imagecreatefromstring($output);
        $minX = PHP_INT_MAX;
        $maxX = -1;
        for ($y = 430; $y < 1070; $y++) {
            for ($x = 515; $x < 1155; $x++) {
                if ((imagecolorat($rendered, $x, $y) & 0xFFFFFF) === 0) {
                    $minX = min($minX, $x);
                    $maxX = max($maxX, $x);
                }
            }
        }
        $this->assertGreaterThanOrEqual(0.80, ($maxX - $minX + 1) / 640);

        $preview = imagescale($rendered, 320, 320, IMG_BILINEAR_FIXED);
        ob_start();
        imagejpeg($preview, null, 75);
        $jpeg = (string) ob_get_clean();

        $this->assertSame($payload, $this->decodedPayload($jpeg));
    }

    public function test_unreadable_square_image_keeps_the_original_bytes(): void
    {
        $image = imagecreatetruecolor(240, 240);
        imagefilledrectangle($image, 0, 0, 239, 239, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();

        $this->assertSame($contents, (new QrTemplateCompositor)->compose(
            $contents,
            new QrTemplatePlacement($this->fixture('template-square-box.png'), 250, 250, 520, 520),
        ));
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
