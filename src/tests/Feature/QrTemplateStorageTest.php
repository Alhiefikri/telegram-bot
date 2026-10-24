<?php

namespace Tests\Feature;

use App\Services\Payments\QrTemplateStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

final class QrTemplateStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_bytes_are_stored_verbatim_under_the_template_directory(): void
    {
        Storage::fake('public');
        $contents = $this->fixture('template-square-box.png');

        $path = app(QrTemplateStorage::class)->store(
            UploadedFile::fake()->createWithContent('template.png', $contents),
        );

        $this->assertStringStartsWith('payment-qr-templates/', $path);
        $this->assertStringEndsWith('.png', $path);
        $this->assertSame($contents, Storage::disk('public')->get($path));
    }

    public function test_unsupported_image_types_are_rejected(): void
    {
        Storage::fake('public');

        $this->expectException(RuntimeException::class);

        app(QrTemplateStorage::class)->store(
            UploadedFile::fake()->createWithContent('template.gif', $this->fixture('template-square-box.png')),
        );
    }

    public function test_delete_only_removes_files_inside_the_template_directory(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('payment-qr-templates/keep.png', 'x');
        Storage::disk('public')->put('products/other.png', 'x');

        $storage = app(QrTemplateStorage::class);
        $storage->delete('products/other.png');
        $storage->delete('payment-qr-templates/../products/other.png');
        $storage->delete('payment-qr-templates/keep.png');

        $this->assertFalse(Storage::disk('public')->exists('payment-qr-templates/keep.png'));
        $this->assertTrue(Storage::disk('public')->exists('products/other.png'));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(base_path('tests/Fixtures/qr/'.$name));
        $this->assertIsString($contents);

        return $contents;
    }
}
