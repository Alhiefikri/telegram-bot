<?php

namespace Tests\Unit\Services\Telegram;

use App\Services\PaidInvoice\PaidInvoicePresentation;
use App\Services\Telegram\SalesProofPostFormatter;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class SalesProofPostFormatterTest extends TestCase
{
    public function test_photo_caption_is_bounded_and_uses_the_public_paid_order_shape(): void
    {
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'INV-20260822-01K3B8Q7T3P6M4R2N9X5C1V0Z8',
            buyerIdentifier: '284*****24',
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
            buyerName: 'And***',
        );

        $caption = app(SalesProofPostFormatter::class)->format($presentation, 17);

        $this->assertLessThanOrEqual(SalesProofPostFormatter::MAX_CAPTION_LENGTH, mb_strlen($caption, 'UTF-8'));
        $this->assertStringContainsString('ORDER PAYMENT CONFIRMED', $caption);
        $this->assertStringContainsString('Buyer: And***', $caption);
        $this->assertStringNotContainsString('Buyer ID:', $caption);
        $this->assertStringContainsString('Order ID: INV-20260822-01K3B8Q7T3P6M4R2N9X5C1V0Z8', $caption);
        $this->assertStringContainsString('Item: Long Unicode Product', $caption);
        $this->assertStringContainsString('× 1', $caption);
        $this->assertStringContainsString('Paid via: QRIS', $caption);
        $this->assertStringContainsString('Completed: 22 August 2026, 11:00 WIB', $caption);
        $this->assertStringContainsString('Subtotal: Rp 123.456', $caption);
        $this->assertStringContainsString('Amount paid: Rp 123.456', $caption);
        $this->assertStringContainsString('Successful orders: #17', $caption);
        $this->assertStringContainsString('Thank you for choosing Lhie Store.', $caption);
        $this->assertStringContainsString('…', $caption);
        $this->assertStringNotContainsStringIgnoringCase('fake', $caption);
    }

    public function test_photo_caption_uses_a_safe_generic_name_instead_of_a_buyer_id_fallback(): void
    {
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'INV-NAME-FALLBACK',
            buyerIdentifier: '284*****24',
            paidAt: CarbonImmutable::parse('2026-08-22 04:00:00 UTC')->setTimezone('Asia/Jakarta'),
            paymentMethod: 'QRIS',
            currency: 'IDR',
            items: [],
            providerFee: 0,
            totalPaid: 10000,
        );

        $caption = app(SalesProofPostFormatter::class)->format($presentation, 18);

        $this->assertStringContainsString('Buyer: Buyer', $caption);
        $this->assertStringNotContainsString('Buyer ID:', $caption);
        $this->assertStringNotContainsString('284*****24', $caption);
    }
}
