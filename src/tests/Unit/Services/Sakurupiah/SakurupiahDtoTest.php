<?php

namespace Tests\Unit\Services\Sakurupiah;

use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahCallback;
use App\Services\Sakurupiah\SakurupiahChannel;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahPayloadSanitizer;
use App\Services\Sakurupiah\SakurupiahSchemaException;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use InvalidArgumentException;
use Tests\TestCase;

class SakurupiahDtoTest extends TestCase
{
    public function test_create_invoice_preserves_submitted_amount_string_in_provider_body(): void
    {
        $invoice = $this->invoice();

        $body = $invoice->toProviderPayload('api-test', 'signature-test');

        $this->assertSame('0012000', $body['amount']);
        $this->assertSame('QRISC', $body['method']);
        $this->assertSame(1, $body['merchant_fee']);
        $this->assertSame('INV-408', $body['merchant_ref']);
    }

    public function test_create_invoice_rejects_non_qris_methods(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SakurupiahCreateInvoice(
            method: 'VA',
            phone: '08123456789',
            amount: 12000,
            merchantFee: 1,
            merchantRef: 'INV-408',
            callbackUrl: 'https://store.example.test/api/webhook/sakurupiah',
            returnUrl: 'https://store.example.test/shop/orders/INV-408',
        );
    }

    public function test_channel_parser_rejects_fee_percentage_above_one_hundred(): void
    {
        $this->expectException(SakurupiahSchemaException::class);

        SakurupiahChannel::fromPayload([
            'kode' => 'QRISC',
            'nama' => 'QRISC Custom Merchant',
            'minimal' => '1',
            'maksimal' => '2',
            'tipe' => 'DIRECT',
            'status' => 'Aktif',
            'biaya' => '101%',
        ]);
    }

    public function test_channel_parser_normalizes_provider_fee_unit_markers(): void
    {
        $percentage = SakurupiahChannel::fromPayload([
            'kode' => 'QRISMU',
            'nama' => 'QRISMU',
            'minimal' => '500',
            'maksimal' => '5000000',
            'biaya' => '0.8',
            'percent' => 'Percent',
            'tipe' => 'DIRECT',
            'status' => 'Aktif',
        ]);

        $nominal = SakurupiahChannel::fromPayload([
            'kode' => 'BCAVA',
            'nama' => 'BCA Virtual-Account',
            'minimal' => '10000',
            'maksimal' => '20000000',
            'biaya' => '5500',
            'percent' => 'Nominal',
            'tipe' => 'DIRECT',
            'status' => 'Aktif',
        ]);

        $this->assertSame('0.8%', $percentage->fee?->submitted());
        $this->assertSame('0.8', $percentage->percent);
        $this->assertSame('5500', $nominal->fee?->submitted());
        $this->assertNull($nominal->percent);
        $this->assertSame(5500, $nominal->feeAmount());
    }

    public function test_invoice_parser_accepts_qualified_scalar_variants_and_exposes_typed_values(): void
    {
        $invoice = SakurupiahInvoice::fromPayload([
            'status' => '200',
            'data' => [[
                'trx_id' => 'trx-408',
                'merchant_ref' => 'INV-408',
                'payment_kode' => 'QRISC',
                'payment_status' => 'pending',
                'amount' => '0012000',
                'total' => 12084,
                'fee' => '84',
                'date' => '2026-09-02',
                'merchant_fee' => 'Merchant',
                'time' => '12:00:00',
                'expired' => '2026-09-03 12:00:00',
                'qr' => 'raw-qr-payload',
                'checkout_url' => 'https://sakurupiah.id/pay?token=secret',
            ]],
        ], $this->invoice());

        $this->assertSame('trx-408', $invoice->transactionId());
        $this->assertSame(SakurupiahTransactionStatus::Pending, $invoice->status);
        $this->assertSame(12084, $invoice->totalValue());
        $this->assertSame(12000, $invoice->amountValue());
        $this->assertSame(1, $invoice->merchantFee());
        $this->assertTrue($invoice->hasTokenizedCheckoutUrl());
        $this->assertTrue($invoice->hasQrPayload());
        $this->assertSame('Asia/Jakarta', $invoice->createdAt?->getTimezone()->getName());
        $this->assertFalse(SakurupiahPayloadSanitizer::containsSensitiveKeys($invoice->sanitizedPayload()));
        $this->assertArrayNotHasKey('qr', $invoice->sanitizedPayload()['data'][0]);
        $this->assertArrayNotHasKey('checkout_url', $invoice->sanitizedPayload()['data'][0]);
    }

    public function test_status_response_can_use_requested_transaction_identity(): void
    {
        $status = SakurupiahStatus::fromPayload([
            'status' => '200',
            'data' => [['status' => 'pending']],
        ], 'trx-408');

        $this->assertSame('trx-408', $status->transactionId());
        $this->assertSame(SakurupiahTransactionStatus::Pending, $status->status);
    }

    public function test_parsers_reject_missing_identity_invalid_status_overflow_and_malformed_date(): void
    {
        $this->expectException(SakurupiahSchemaException::class);
        SakurupiahInvoice::fromPayload([
            'data' => [[
                'merchant_ref' => 'INV-408',
                'payment_kode' => 'QRISC',
                'payment_status' => 'pending',
                'total' => '12000',
            ]],
        ]);
    }

    public function test_status_parser_rejects_unknown_status(): void
    {
        $this->expectException(SakurupiahSchemaException::class);
        SakurupiahStatus::fromPayload(['data' => [['status' => 'success']]], 'trx-408');
    }

    public function test_invoice_parser_rejects_overflow_and_malformed_dates(): void
    {
        try {
            SakurupiahInvoice::fromPayload(['data' => [[
                'trx_id' => 'trx-408',
                'merchant_ref' => 'INV-408',
                'payment_kode' => 'QRISC',
                'payment_status' => 'pending',
                'total' => '999999999999999999999999999999',
            ]]], $this->invoice());
            $this->fail('Expected amount overflow to fail.');
        } catch (SakurupiahSchemaException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(SakurupiahSchemaException::class);
        SakurupiahInvoice::fromPayload(['data' => [[
            'trx_id' => 'trx-408',
            'merchant_ref' => 'INV-408',
            'payment_kode' => 'QRISC',
            'payment_status' => 'pending',
            'total' => '12000',
            'date' => '2026-99-99',
            'time' => '12:00:00',
        ]]], $this->invoice());
    }

    public function test_callback_requires_exact_event_and_status_code_pair(): void
    {
        $callback = SakurupiahCallback::fromRawBody('{"trx_id":"trx-408","merchant_ref":"INV-408","status":"pending","status_kode":0}');

        $this->assertSame('payment_status', $callback->event);
        $this->assertSame(SakurupiahTransactionStatus::Pending, $callback->status);

        $this->expectException(SakurupiahSchemaException::class);
        SakurupiahCallback::fromPayload([
            'trx_id' => 'trx-408',
            'merchant_ref' => 'INV-408',
            'status' => 'pending',
            'status_kode' => 1,
        ]);
    }

    public function test_callback_rejects_expired_code_until_provider_mapping_is_confirmed(): void
    {
        $this->expectException(SakurupiahSchemaException::class);

        SakurupiahCallback::fromPayload([
            'trx_id' => 'trx-408',
            'merchant_ref' => 'INV-408',
            'status' => 'expired',
            'status_kode' => -2,
        ]);
    }

    public function test_amount_rejects_unsafe_numeric_forms(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SakurupiahAmount::from(PHP_INT_MAX.'0');
    }

    private function invoice(): SakurupiahCreateInvoice
    {
        return new SakurupiahCreateInvoice(
            method: 'QRISC',
            phone: '08123456789',
            amount: '0012000',
            merchantFee: 1,
            merchantRef: 'INV-408',
            callbackUrl: 'https://store.example.test/api/webhook/sakurupiah',
            returnUrl: 'https://store.example.test/shop/orders/INV-408',
        );
    }
}
