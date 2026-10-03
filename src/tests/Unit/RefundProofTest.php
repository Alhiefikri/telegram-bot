<?php

namespace Tests\Unit;

use App\Services\Warranty\RefundProof;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RefundProofTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAC0lEQVQImWNgQAYAAA4AAbGa6gYAAAAASUVORK5CYII=';

    public function test_a_png_is_preserved_exactly_without_editing_customer_evidence(): void
    {
        $bytes = base64_decode(self::PNG, true);
        $proof = RefundProof::fromBytes($bytes);
        $this->assertSame('image/png', $proof->mime);
        $this->assertSame($bytes, $proof->bytes);
        $this->assertSame('proof.png', $proof->filename);
    }

    public static function unsafeProofs(): array
    {
        return [
            'empty' => [''], 'disguised html' => ['<html>proof</html>'],
            'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
            'pdf' => ['%PDF-1.7'], 'oversized' => [str_repeat('x', 4_194_305)],
            'corrupt png pixels' => [base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=')],
        ];
    }

    #[DataProvider('unsafeProofs')]
    public function test_invalid_or_oversized_evidence_is_rejected_without_echoing_file_data(string $bytes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid refund proof.');
        RefundProof::fromBytes($bytes);
    }
}
