<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\PaidInvoice\PaidInvoicePresentation;
use App\Services\Telegram\SalesProofPostFormatter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SalesProofPostFormatterTest extends TestCase
{
    public function test_photo_caption_is_bounded_and_truthful_for_a_synthetic_presentation(): void
    {
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'FAKE-TEST-ORDER-001',
            buyerIdentifier: 'FAKE***',
            paidAt: CarbonImmutable::parse('2026-08-22 04:00:00 UTC')->setTimezone('Asia/Jakarta'),
            paymentMethod: 'QRIS',
            currency: 'IDR',
            items: [[
                'product_name' => str_repeat('Long Unicode Product 名 ', 200),
                'quantity' => 1,
                'unit_price' => 123456,
                'subtotal' => 123456,
            ]],
            providerFee: 0,
            totalPaid: 123456,
        );

        $caption = app(SalesProofPostFormatter::class)->format($presentation, 17);

        $this->assertLessThanOrEqual(SalesProofPostFormatter::MAX_CAPTION_LENGTH, mb_strlen($caption, 'UTF-8'));
        $this->assertStringContainsString('ORDER PAYMENT CONFIRMED', $caption);
        $this->assertStringContainsString('Buyer ID: FAKE***', $caption);
        $this->assertStringContainsString('Order ID: FAKE-TEST-ORDER-001', $caption);
        $this->assertStringContainsString('Item: Long Unicode Product', $caption);
        $this->assertStringContainsString('× 1', $caption);
        $this->assertStringContainsString('Paid via: QRIS', $caption);
        $this->assertStringContainsString('Completed: 22 August 2026, 11:00 WIB', $caption);
        $this->assertStringContainsString('Subtotal: Rp 123.456', $caption);
        $this->assertStringContainsString('Amount paid: Rp 123.456', $caption);
        $this->assertStringContainsString('Successful orders: #17', $caption);
        $this->assertStringContainsString('Thank you for choosing Lhie Store.', $caption);
        $this->assertStringContainsString('…', $caption);
    }
}
