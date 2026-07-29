<?php

namespace Tests\Unit\Services\KlikQris;

use App\Models\Payment;
use App\Services\KlikQris\KlikQrisAmount;
use App\Services\KlikQris\KlikQrisCallback;
use App\Services\KlikQris\KlikQrisPayloadSanitizer;
use App\Services\KlikQris\KlikQrisTransaction;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class KlikQrisTransactionTest extends TestCase
{
    #[DataProvider('validWholeRupiahValues')]
    public function test_amount_accepts_exact_whole_rupiah_integer_or_decimal_strings(mixed $value, int $rupiah, string $display): void
    {
        $amount = KlikQrisAmount::from($value);

        $this->assertSame($rupiah, $amount->rupiah());
        $this->assertSame($display, $amount->display());
    }

    public static function validWholeRupiahValues(): array
    {
        return [
            'integer' => [1016, 1016, '1016'],
            'integer string' => ['1016', 1016, '1016'],
            'one decimal zero' => ['1016.0', 1016, '1016.0'],
            'two decimals zero' => ['1016.00', 1016, '1016.00'],
        ];
    }

    #[DataProvider('invalidWholeRupiahValues')]
    public function test_amount_rejects_values_that_are_not_exact_positive_whole_rupiah(mixed $value): void
    {
        $this->expectException(UnexpectedValueException::class);

        KlikQrisAmount::from($value);
    }

    public static function invalidWholeRupiahValues(): array
    {
        return [
            'non zero fraction' => ['1016.01'],
            'negative integer' => [-1],
            'negative string' => ['-1'],
            'zero integer' => [0],
            'zero string' => ['0'],
            'exponent notation' => ['1e3'],
            'comma decimal' => ['1,016'],
            'boolean true' => [true],
            'array' => [[1016]],
            'float' => [1016.0],
        ];
    }

    #[DataProvider('sandboxQrisUrls')]
    public function test_sandbox_qris_url_allowlist_accepts_only_documented_storage_sandbox_shape(string $url, bool $expected): void
    {
        $this->assertSame($expected, KlikQrisTransaction::isAllowedSandboxQrisUrl($url));
    }

    public static function sandboxQrisUrls(): array
    {
        return [
            'documented sandbox storage png' => ['https://klikqris.com/storage/sandbox/qris_STEST-123.png', true],
            'provider sandbox sample png' => ['https://klikqris.com/storage/sandbox/sandbox_sample.png', true],
            'query secret rejected' => ['https://klikqris.com/storage/sandbox/qris_STEST-123.png?token=secret-query', false],
            'fragment secret rejected' => ['https://klikqris.com/storage/sandbox/qris_STEST-123.png#secret-fragment', false],
            'nested sandbox storage path rejected' => ['https://klikqris.com/storage/sandbox/merchant/qris_STEST-123.png', false],
            'http rejected' => ['http://klikqris.com/storage/sandbox/qris_STEST-123.png', false],
            'subdomain rejected' => ['https://evil.klikqris.com/storage/sandbox/qris_STEST-123.png', false],
            'suffix host rejected' => ['https://klikqris.com.evil.test/storage/sandbox/qris_STEST-123.png', false],
            'userinfo spoof rejected' => ['https://klikqris.com@evil.test/storage/sandbox/qris_STEST-123.png', false],
            'explicit port rejected' => ['https://klikqris.com:443/storage/sandbox/qris_STEST-123.png', false],
            'old sandbox root rejected' => ['https://klikqris.com/sandbox/qris/STEST-123', false],
            'unrelated storage path rejected' => ['https://klikqris.com/storage/production/qris_STEST-123.png', false],
            'encoded traversal rejected' => ['https://klikqris.com/storage/sandbox/%2e%2e/production/qris.png', false],
            'literal traversal rejected' => ['https://klikqris.com/storage/sandbox/../production/qris.png', false],
            'not a png rejected' => ['https://klikqris.com/storage/sandbox/qris_STEST-123.svg', false],
        ];
    }

    public function test_transaction_normalizes_payload_and_removes_secrets_recursively_without_removing_qris_url(): void
    {
        $transaction = KlikQrisTransaction::fromPayload($this->transactionPayload([
            'nested' => [
                'signature' => 'nested-signature',
                'qris_image' => 'nested-base64',
                'qris_url' => 'https://klikqris.com/qr/nested',
                'api_key' => 'test-only-key',
                'Authorization' => 'Bearer test-only-token',
                'access_token' => 'test-token',
                'client_secret' => 'test-secret',
                'safe' => 'kept',
            ],
        ]));

        $this->assertSame('STEST-123', $transaction->orderId());
        $this->assertSame(1000, $transaction->amount()->rupiah());
        $this->assertSame('1000.00', $transaction->amount()->display());
        $this->assertSame(1016, $transaction->totalAmount());
        $this->assertSame('1016.00', $transaction->totalAmountDisplay());
        $this->assertSame('PENDING', $transaction->status());
        $this->assertSame('https://klikqris.com/qr/STEST-123', $transaction->qrisUrl());
        $this->assertSame('opaque-test-qris-image', $transaction->qrisImageBase64());
        $this->assertSame('opaque-test-signature', $transaction->signature());
        $this->assertTrue($transaction->expiresAt()->equalTo(CarbonImmutable::parse('2026-07-28T07:00:00+00:00')));
        $this->assertTrue($transaction->paidAt()->equalTo(CarbonImmutable::parse('2026-07-28T06:50:00+00:00')));
        $this->assertTrue($transaction->isPending());
        $this->assertFalse($transaction->isSuccess());

        $sanitized = $transaction->sanitizedPayload();
        $this->assertSame('https://klikqris.com/qr/STEST-123', $sanitized['data']['qris_url']);
        $this->assertSame('PENDING', $sanitized['data']['status']);
        $this->assertSame('1000.00', $sanitized['data']['amount']);
        $this->assertSame('1016.00', $sanitized['data']['total_amount']);
        $this->assertSame('kept', $sanitized['data']['nested']['safe']);
        $this->assertSame('https://klikqris.com/qr/nested', $sanitized['data']['nested']['qris_url']);
        $this->assertArrayNotHasKey('signature', $sanitized['data']);
        $this->assertArrayNotHasKey('qris_image', $sanitized['data']);
        $this->assertArrayNotHasKey('signature', $sanitized['data']['nested']);
        $this->assertArrayNotHasKey('qris_image', $sanitized['data']['nested']);
        $this->assertArrayNotHasKey('api_key', $sanitized['data']['nested']);
        $this->assertArrayNotHasKey('Authorization', $sanitized['data']['nested']);
        $this->assertArrayNotHasKey('access_token', $sanitized['data']['nested']);
        $this->assertArrayNotHasKey('client_secret', $sanitized['data']['nested']);
    }

    public function test_naive_provider_timestamps_are_interpreted_as_asia_jakarta(): void
    {
        $originalTimezone = date_default_timezone_get();
        date_default_timezone_set('Asia/Makassar');

        try {
            $transaction = KlikQrisTransaction::fromPayload($this->transactionPayload([
                'expired_at' => '2026-07-29 14:22:32',
                'paid_at' => '2026-07-29 14:10:00',
            ]));
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        $this->assertTrue($transaction->expiresAt()->equalTo(CarbonImmutable::parse('2026-07-29T07:22:32+00:00')));
        $this->assertTrue($transaction->paidAt()->equalTo(CarbonImmutable::parse('2026-07-29T07:10:00+00:00')));
    }

    public function test_payload_sanitizer_removes_merchant_credential_key_variants_recursively(): void
    {
        $sanitized = KlikQrisPayloadSanitizer::sanitize([
            'id_merchant' => 'MERCHANT_SECRET_ONE',
            'merchant_id' => 'MERCHANT_SECRET_TWO',
            'merchant' => 'MERCHANT_SECRET_THREE',
            'idmerchant' => 'MERCHANT_SECRET_FOUR',
            'ID-MERCHANT' => 'MERCHANT_SECRET_FIVE',
            'nested' => [
                'merchantId' => 'MERCHANT_SECRET_SIX',
                'merchant_identifier' => 'MERCHANT_SECRET_SEVEN',
                'safe_merchant_display_name' => 'Safe Merchant Label',
            ],
            'safe' => 'kept',
        ]);

        $json = json_encode($sanitized, JSON_THROW_ON_ERROR);

        $this->assertSame('kept', $sanitized['safe']);
        $this->assertSame('Safe Merchant Label', $sanitized['nested']['safe_merchant_display_name']);
        foreach (['ONE', 'TWO', 'THREE', 'FOUR', 'FIVE', 'SIX', 'SEVEN'] as $suffix) {
            $this->assertStringNotContainsString('MERCHANT_SECRET_'.$suffix, $json);
        }
    }

    public function test_transaction_assert_matches_payment_identity_and_total_when_present(): void
    {
        $transaction = KlikQrisTransaction::fromPayload($this->transactionPayload());
        $payment = new Payment([
            'provider' => 'klikqris',
            'merchant_ref' => 'STEST-123',
            'amount' => 1000,
            'provider_total_payment' => 1016,
        ]);

        $transaction->assertMatches($payment);

        $this->assertTrue(true);
    }

    #[DataProvider('mismatchedPayments')]
    public function test_transaction_assert_matches_fails_closed_with_field_specific_messages(array $attributes, string $message): void
    {
        $transaction = KlikQrisTransaction::fromPayload($this->transactionPayload());
        $payment = new Payment(array_merge([
            'provider' => 'klikqris',
            'merchant_ref' => 'STEST-123',
            'amount' => 1000,
            'provider_total_payment' => 1016,
        ], $attributes));

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        $transaction->assertMatches($payment);
    }

    public static function mismatchedPayments(): array
    {
        return [
            'provider' => [['provider' => 'pakasir'], 'KlikQRIS provider mismatch.'],
            'order id' => [['merchant_ref' => 'STEST-999'], 'KlikQRIS order_id mismatch.'],
            'amount' => [['amount' => 999], 'KlikQRIS amount mismatch.'],
            'total amount' => [['provider_total_payment' => 1017], 'KlikQRIS total_amount mismatch.'],
        ];
    }

    public function test_transaction_rejects_unsupported_status(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('KlikQRIS status is unsupported.');

        KlikQrisTransaction::fromPayload($this->transactionPayload(['status' => 'PAID']));
    }

    public function test_transaction_allows_success_status_without_qris_media(): void
    {
        $transaction = KlikQrisTransaction::fromPayload($this->transactionPayload([
            'status' => 'success',
            'qris_url' => null,
            'qris_image' => null,
        ]));

        $this->assertTrue($transaction->isSuccess());
        $this->assertFalse($transaction->isPending());
        $this->assertNull($transaction->qrisUrl());
        $this->assertNull($transaction->qrisImageBase64());
    }

    public function test_pending_create_transaction_requires_qris_url_or_image(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('KlikQRIS pending transaction requires qris_url or qris_image.');

        KlikQrisTransaction::fromCreatePayload($this->transactionPayload([
            'qris_url' => '',
            'qris_image' => '',
        ]));
    }

    public function test_pending_status_transaction_allows_missing_qris_media(): void
    {
        $transaction = KlikQrisTransaction::fromStatusPayload($this->transactionPayload([
            'status' => 'PENDING',
            'qris_url' => null,
            'qris_image' => null,
        ]));

        $this->assertTrue($transaction->isPending());
        $this->assertNull($transaction->qrisUrl());
        $this->assertNull($transaction->qrisImageBase64());
    }

    #[DataProvider('invalidTransactionTimestamps')]
    public function test_transaction_fails_closed_for_invalid_present_timestamps(array $overrides): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('KlikQRIS timestamp is invalid.');

        KlikQrisTransaction::fromStatusPayload($this->transactionPayload($overrides));
    }

    public static function invalidTransactionTimestamps(): array
    {
        return [
            'invalid expired_at string' => [['expired_at' => 'not-a-date']],
            'non scalar expired_at' => [['expired_at' => ['2026-07-28T07:00:00+00:00']]],
            'invalid paid_at string' => [['paid_at' => 'not-a-date']],
            'non scalar paid_at' => [['paid_at' => ['2026-07-28T06:50:00+00:00']]],
        ];
    }

    public function test_callback_paid_parses_as_trigger_and_sanitizes_signature(): void
    {
        $callback = KlikQrisCallback::fromPayload([
            'order_id' => 'STEST-123',
            'amount' => '1000',
            'total_amount' => '1016.00',
            'status' => 'paid',
            'signature' => 'opaque-test-signature',
            'qris_url' => 'https://klikqris.com/qr/callback',
        ]);

        $this->assertSame('STEST-123', $callback->orderId());
        $this->assertSame(1000, $callback->amount()->rupiah());
        $this->assertSame(1016, $callback->totalAmount());
        $this->assertSame('PAID', $callback->status());
        $this->assertTrue($callback->isPaidTrigger());
        $this->assertArrayNotHasKey('signature', $callback->sanitizedPayload());
        $this->assertSame('https://klikqris.com/qr/callback', $callback->sanitizedPayload()['qris_url']);
    }

    #[DataProvider('malformedCallbacks')]
    public function test_callback_fails_closed_for_malformed_identity_amounts_or_status(array $payload, string $message): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        KlikQrisCallback::fromPayload($payload);
    }

    public static function malformedCallbacks(): array
    {
        return [
            'blank order id' => [[
                'order_id' => '   ',
                'amount' => '1000',
                'total_amount' => '1016.00',
                'status' => 'PAID',
            ], 'KlikQRIS order_id is required.'],
            'invalid amount' => [[
                'order_id' => 'STEST-123',
                'amount' => '1000.01',
                'total_amount' => '1016.00',
                'status' => 'PAID',
            ], 'KlikQRIS amount must be a positive whole-rupiah value.'],
            'unknown status' => [[
                'order_id' => 'STEST-123',
                'amount' => '1000',
                'total_amount' => '1016.00',
                'status' => 'SETTLED',
            ], 'KlikQRIS callback status is unsupported.'],
        ];
    }

    private function transactionPayload(array $overrides = []): array
    {
        return [
            'ok' => true,
            'data' => array_merge([
                'order_id' => 'STEST-123',
                'amount' => '1000.00',
                'total_amount' => '1016.00',
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/qr/STEST-123',
                'qris_image' => 'opaque-test-qris-image',
                'expired_at' => '2026-07-28T07:00:00+00:00',
                'paid_at' => '2026-07-28T06:50:00+00:00',
                'signature' => 'opaque-test-signature',
            ], $overrides),
        ];
    }
}
