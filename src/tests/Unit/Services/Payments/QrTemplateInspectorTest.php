<?php

namespace Tests\Unit\Services\Payments;

use App\Services\Payments\QrTemplateInspector;
use PHPUnit\Framework\TestCase;

class QrTemplateInspectorTest extends TestCase
{
    public function test_reports_dimensions_of_a_supported_image(): void
    {
        $this->assertSame(
            ['width' => 1000, 'height' => 1000],
            (new QrTemplateInspector)->dimensions($this->fixture('template-square-box.png')),
        );
    }

    public function test_rejects_content_that_is_not_an_image(): void
    {
        $inspector = new QrTemplateInspector;

        $this->assertNull($inspector->dimensions($this->fixture('corrupt.bin')));
        $this->assertNull($inspector->nearWhiteCoverage($this->fixture('corrupt.bin'), 0, 0, 10, 10));
    }

    public function test_white_reserved_area_scores_full_coverage(): void
    {
        $coverage = (new QrTemplateInspector)->nearWhiteCoverage(
            $this->fixture('template-square-box.png'),
            250,
            250,
            520,
            520,
        );

        $this->assertSame(1.0, $coverage);
    }

    public function test_area_over_the_blue_frame_scores_zero_coverage(): void
    {
        $coverage = (new QrTemplateInspector)->nearWhiteCoverage(
            $this->fixture('template-square-box.png'),
            0,
            0,
            240,
            240,
        );

        $this->assertSame(0.0, $coverage);
    }

    public function test_partial_overlap_reports_a_fraction_below_the_admin_threshold(): void
    {
        $coverage = (new QrTemplateInspector)->nearWhiteCoverage(
            $this->fixture('template-square-box.png'),
            200,
            200,
            400,
            400,
        );

        $this->assertNotNull($coverage);
        $this->assertLessThan(0.9, $coverage);
        $this->assertGreaterThan(0.0, $coverage);
    }

    public function test_area_outside_the_image_is_unmeasurable(): void
    {
        $this->assertNull((new QrTemplateInspector)->nearWhiteCoverage(
            $this->fixture('template-square-box.png'),
            900,
            900,
            500,
            500,
        ));
    }

    public function test_large_areas_are_sampled_without_losing_accuracy(): void
    {
        $image = imagecreatetruecolor(1200, 1200);
        imagefilledrectangle($image, 0, 0, 1199, 1199, imagecolorallocate($image, 255, 255, 255));
        imagefilledrectangle($image, 0, 0, 599, 1199, imagecolorallocate($image, 10, 10, 10));
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();

        $coverage = (new QrTemplateInspector)->nearWhiteCoverage($contents, 0, 0, 1200, 1200);

        $this->assertNotNull($coverage);
        $this->assertEqualsWithDelta(0.5, $coverage, 0.01);
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(__DIR__.'/../../../Fixtures/qr/'.$name);
        $this->assertIsString($contents);

        return $contents;
    }
}
