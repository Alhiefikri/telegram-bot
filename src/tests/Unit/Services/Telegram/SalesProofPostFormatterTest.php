<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\TelegramUiIcon;
use App\Services\PaidInvoice\PaidInvoicePresentation;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\SalesProofPostFormatter;
use App\Services\Telegram\TelegramHtmlCaption;
use App\Services\Telegram\TelegramUiIcons;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SalesProofPostFormatterTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_custom_emoji_tags_remain_intact_under_the_visible_caption_limit(): void
    {
        TelegramUiIcon::query()->where('key', 'message.users')->update([
            'custom_emoji_id' => '5368324170671202286',
        ]);
        Cache::forget(TelegramUiIcons::CACHE_KEY);
        app(CustomEmojiCapability::class)->markUnavailable(
            'Bad Request: custom emoji is unavailable in this bot chat',
            CustomEmojiCapability::SCOPE_MESSAGES,
        );

        $presentation = new PaidInvoicePresentation(
            invoiceId: 'INV-CUSTOM-EMOJI-LIMIT',
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

        $this->assertStringContainsString('emoji-id="5368324170671202286"', $caption);
        $this->assertStringContainsString('👥</tg-emoji>', $caption);
        $this->assertLessThanOrEqual(
            SalesProofPostFormatter::MAX_CAPTION_LENGTH,
            app(TelegramHtmlCaption::class)->parsedLength($caption, ['parse_mode' => 'HTML']),
        );
    }

    public function test_order_item_icon_snapshot_is_rendered_without_using_static_icon_slots(): void
    {
        app(CustomEmojiCapability::class)->markAvailable();
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'INV-PRODUCT-ICON-SNAPSHOT',
            buyerIdentifier: '284*****24',
            paidAt: CarbonImmutable::parse('2026-08-22 04:00:00 UTC')->setTimezone('Asia/Jakarta'),
            paymentMethod: 'QRIS',
            currency: 'IDR',
            items: [[
                'product_name' => 'Snapshot Product',
                'product_icon' => '🧩',
                'product_custom_emoji_id' => '5368324170671202286',
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
            ]],
            providerFee: 0,
            totalPaid: 10000,
        );

        $caption = app(SalesProofPostFormatter::class)->format($presentation, 19);

        $this->assertStringContainsString(
            '<tg-emoji emoji-id="5368324170671202286" data-omp-icon="1">🧩</tg-emoji> Snapshot Product',
            $caption,
        );
        $this->assertStringNotContainsString('emoji-id="5368324170671202287"', $caption);
    }

    public function test_product_icon_snapshot_without_custom_emoji_uses_its_plain_glyph(): void
    {
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'INV-PRODUCT-ICON-GLYPH',
            buyerIdentifier: '284*****24',
            paidAt: CarbonImmutable::parse('2026-08-22 04:00:00 UTC')->setTimezone('Asia/Jakarta'),
            paymentMethod: 'QRIS',
            currency: 'IDR',
            items: [[
                'product_name' => 'Snapshot Product',
                'product_icon' => '🧩',
                'product_custom_emoji_id' => null,
                'quantity' => 1,
                'unit_price' => 10000,
                'subtotal' => 10000,
            ]],
            providerFee: 0,
            totalPaid: 10000,
        );

        $caption = app(SalesProofPostFormatter::class)->format($presentation, 20);

        $this->assertStringContainsString('🧩 Snapshot Product', $caption);
        $this->assertStringNotContainsString('<tg-emoji', $caption);
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

    public function test_real_tier_pricing_line_uses_only_the_immutable_item_snapshot(): void
    {
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'INV-TIER-PROOF',
            buyerIdentifier: '284*****24',
            paidAt: CarbonImmutable::parse('2026-08-22 04:00:00 UTC')->setTimezone('Asia/Jakarta'),
            paymentMethod: 'QRIS',
            currency: 'IDR',
            items: [[
                'product_name' => 'Tiered Product',
                'quantity' => 10,
                'unit_price' => 23_000,
                'subtotal' => 230_000,
                'quantity_price_tier_minimum' => 10,
            ]],
            providerFee: 0,
            totalPaid: 230_000,
            buyerName: 'And***',
        );

        $caption = app(SalesProofPostFormatter::class)->format($presentation, 19);

        $this->assertStringContainsString('🏷 Bulk pricing applied · 10+ units', $caption);
    }

    public function test_top_up_caption_is_bounded_and_never_inflates_the_order_counter(): void
    {
        $presentation = new PaidInvoicePresentation(
            invoiceId: 'BALANCE-9F3K2M7Q',
            buyerIdentifier: '858*****05',
            buyerName: 'Cia***',
            paidAt: CarbonImmutable::parse('2026-09-13 13:15:00 UTC')->setTimezone('Asia/Jakarta'),
            paymentMethod: 'QRIS',
            currency: 'IDR',
            items: [[
                'product_name' => 'Store Balance Top-Up',
                'quantity' => 1,
                'unit_price' => 50000,
                'subtotal' => 50000,
            ]],
            providerFee: 16,
            totalPaid: 50016,
        );

        $caption = app(SalesProofPostFormatter::class)->formatTopUp($presentation);

        $this->assertLessThanOrEqual(SalesProofPostFormatter::MAX_CAPTION_LENGTH, mb_strlen($caption, 'UTF-8'));
        $this->assertStringContainsString('BALANCE TOP-UP CONFIRMED', $caption);
        $this->assertStringContainsString('Buyer: Cia***', $caption);
        $this->assertStringContainsString('Top-up ID: BALANCE-9F3K2M7Q', $caption);
        $this->assertStringContainsString('Paid via: QRIS', $caption);
        $this->assertStringContainsString('Completed: 13 September 2026, 20:15 WIB', $caption);
        $this->assertStringContainsString('Amount paid: Rp 50.016', $caption);
        $this->assertStringContainsString('Thank you for topping up at Lhie Store.', $caption);
        $this->assertStringNotContainsString('Successful orders:', $caption);
        $this->assertStringNotContainsString('858*****05', $caption);
        $this->assertStringNotContainsString('Bulk pricing applied', $caption);
    }
}
