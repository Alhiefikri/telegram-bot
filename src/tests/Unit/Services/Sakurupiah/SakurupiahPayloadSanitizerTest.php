<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Services\Sakurupiah\SakurupiahPayloadSanitizer;
use PHPUnit\Framework\TestCase;

class SakurupiahPayloadSanitizerTest extends TestCase
{
    public function test_it_keeps_only_safe_provider_facts_and_redacts_sensitive_fields_recursively(): void
    {
        $payload = [
            'status' => '200',
            'message' => 'success',
            'api_id' => 'merchant-api-id',
            'signature' => 'invoice-signature',
            'data' => [[
                'trx_id' => 'trx-408',
                'merchant_ref' => 'INV-408',
                'payment_status' => 'pending',
                'total' => '12000',
                'fee' => '84',
                'nama' => 'Buyer Name',
                'email' => 'buyer@example.test',
                'phone' => '08123456789',
                'qr' => '000201010212secret-base64',
                'payment_no' => '08123456789',
                'checkout_url' => 'https://sakurupiah.id/pay?token=secret',
                'metadata' => [
                    'status' => 'pending',
                    'api_key' => 'secret-key',
                    'safe_note' => 'not allowlisted',
                ],
            ]],
        ];

        $sanitized = SakurupiahPayloadSanitizer::sanitize($payload);

        $this->assertSame([
            'status' => '200',
            'message' => 'success',
            'data' => [[
                'trx_id' => 'trx-408',
                'merchant_ref' => 'INV-408',
                'payment_status' => 'pending',
                'total' => '12000',
                'fee' => '84',
            ]],
        ], $sanitized);
        $this->assertTrue(SakurupiahPayloadSanitizer::containsSensitiveKeys($payload));
        $this->assertFalse(SakurupiahPayloadSanitizer::containsSensitiveKeys($sanitized));
        foreach (['merchant-api-id', 'invoice-signature', 'Buyer Name', 'buyer@example.test', '08123456789', 'secret-base64', 'secret-key', 'token=secret'] as $secret) {
            $this->assertFalse(SakurupiahPayloadSanitizer::containsStringValue($sanitized, $secret), $secret);
        }
    }

    public function test_allowlisted_message_does_not_retain_embedded_credentials(): void
    {
        $sanitized = SakurupiahPayloadSanitizer::sanitize([
            'status' => '400',
            'message' => 'api_key=secret-key',
        ]);

        $this->assertSame(['status' => '400'], $sanitized);
    }

    public function test_it_classifies_provider_secrets_qr_and_buyer_pii_as_sensitive(): void
    {
        foreach (['api_key', 'Authorization', 'callback_signature', 'qr', 'qr_base64', 'payment_no', 'checkout_url', 'access_token', 'customer_email', 'phone'] as $key) {
            $this->assertTrue(SakurupiahPayloadSanitizer::isSensitiveKey($key), $key);
        }

        foreach (['trx_id', 'merchant_ref', 'payment_status', 'total', 'fee', 'status', 'message'] as $key) {
            $this->assertFalse(SakurupiahPayloadSanitizer::isSensitiveKey($key), $key);
        }
    }
}
