<?php

namespace Tests\Unit;

use App\Services\KlikQris\KlikQrisStatusResponseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use UnexpectedValueException;

final class KlikQrisStatusDiagnosticTest extends TestCase
{
    public function test_http_failure_preserves_status_without_exposing_the_response_message(): void
    {
        $failure = new KlikQrisStatusResponseException('private-response-marker', httpStatus: 404);

        $this->assertSame('status_http_404', $failure->diagnosticCode());
        $this->assertStringNotContainsString('private-response-marker', $failure->diagnosticCode());
    }

    public static function validationFailures(): array
    {
        return [
            'identity mismatch' => ['KlikQRIS order_id mismatch.', 'status_order_id_mismatch'],
            'principal mismatch' => ['KlikQRIS amount mismatch.', 'status_amount_mismatch'],
            'payable mismatch' => ['KlikQRIS total_amount mismatch.', 'status_total_amount_mismatch'],
            'missing identity' => ['KlikQRIS order_id is required.', 'status_order_id_missing'],
            'unsupported status' => ['KlikQRIS status is unsupported.', 'status_unsupported'],
            'missing amount' => ['KlikQRIS amount must be an integer or decimal string.', 'status_amount_invalid'],
        ];
    }

    #[DataProvider('validationFailures')]
    public function test_validation_failure_has_a_safe_operational_reason(string $message, string $code): void
    {
        $failure = new KlikQrisStatusResponseException('validation failed', previous: new UnexpectedValueException($message));

        $this->assertSame($code, $failure->diagnosticCode());
    }

    public function test_unknown_exception_text_cannot_escape_through_diagnostics(): void
    {
        $failure = new KlikQrisStatusResponseException('https://private.example/api?token=private-marker', previous: new RuntimeException('secret private-marker'));

        $this->assertSame('status_response_invalid', $failure->diagnosticCode());
        $this->assertStringNotContainsString('private-marker', $failure->diagnosticCode());
    }
}
