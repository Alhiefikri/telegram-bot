<?php

namespace Tests\Unit\Services\Pakasir;

use App\Services\Pakasir\PakasirPayloadSanitizer;
use PHPUnit\Framework\TestCase;

class PakasirPayloadSanitizerTest extends TestCase
{
    public function test_it_removes_sensitive_pakasir_keys_recursively_while_preserving_safe_fields(): void
    {
        $rawPaymentNumber = '00020101021226610016ID.CO.QRIS.ISSUE102';
        $sensitiveValues = [
            $rawPaymentNumber,
            'provider-api-key',
            'provider-x-api-key',
            'provider-camel-api-key',
            'provider-secret',
            'provider-token',
            'provider-password',
            'Bearer provider-authorization',
            'provider-signature',
        ];
        $payload = [
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'INV-102',
                'amount' => 10000,
                'status' => 'pending',
                'fee' => 103,
                'total_payment' => 10103,
                'payment_method' => 'qris',
                'expired_at' => '2026-07-04T16:30:00Z',
                'payment_number' => $sensitiveValues[0],
                'api_key' => $sensitiveValues[1],
                'x-api-key' => $sensitiveValues[2],
                'apiKey' => $sensitiveValues[3],
                'client_secret' => $sensitiveValues[4],
                'access_token' => $sensitiveValues[5],
                'password' => $sensitiveValues[6],
                'Authorization' => $sensitiveValues[7],
                'callback_signature' => $sensitiveValues[8],
                'metadata' => [
                    'status' => 'metadata-pending',
                    'safe_note' => 'keep this',
                    'refresh_token' => $sensitiveValues[5],
                    'nestedPaymentNumber' => $sensitiveValues[0],
                ],
            ],
        ];

        $sanitized = PakasirPayloadSanitizer::sanitize($payload);

        $this->assertSame([
            'payment' => [
                'project' => 'warung-ai',
                'order_id' => 'INV-102',
                'amount' => 10000,
                'status' => 'pending',
                'fee' => 103,
                'total_payment' => 10103,
                'payment_method' => 'qris',
                'expired_at' => '2026-07-04T16:30:00Z',
                'metadata' => [
                    'status' => 'metadata-pending',
                    'safe_note' => 'keep this',
                ],
            ],
        ], $sanitized);
        $this->assertTrue(PakasirPayloadSanitizer::containsSensitiveKeys($payload));
        $this->assertFalse(PakasirPayloadSanitizer::containsSensitiveKeys($sanitized));
        foreach ($sensitiveValues as $sensitiveValue) {
            $this->assertTrue(PakasirPayloadSanitizer::containsStringValue($payload, $sensitiveValue), $sensitiveValue);
            $this->assertFalse(PakasirPayloadSanitizer::containsStringValue($sanitized, $sensitiveValue), $sensitiveValue);
        }
    }

    public function test_it_classifies_api_key_like_and_provider_secret_key_variants_as_sensitive(): void
    {
        foreach ([
            'payment_number',
            'api_key',
            'x-api-key',
            'apiKey',
            'client_secret',
            'access_token',
            'password',
            'Authorization',
            'callback_signature',
        ] as $key) {
            $this->assertTrue(PakasirPayloadSanitizer::isSensitiveKey($key), $key);
        }

        foreach ([
            'project',
            'order_id',
            'amount',
            'status',
            'fee',
            'total_payment',
            'payment_method',
            'expired_at',
        ] as $key) {
            $this->assertFalse(PakasirPayloadSanitizer::isSensitiveKey($key), $key);
        }
    }
}
