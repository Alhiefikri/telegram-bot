<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Product;
use App\Storefront\StorefrontSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_pre_purchase_support_links_include_product_context_for_configured_channels(): void
    {
        config()->set('services.support.whatsapp_number', '+62 812-3456-7890');
        config()->set('services.support.telegram_admin_username', '@lhieadmin');
        $product = Product::factory()->create([
            'name' => 'Claude Pro Support Context',
            'description' => 'password=SHOULD_NOT_APPEAR',
        ]);

        $context = app(StorefrontSupport::class)->prePurchase($product);
        $options = $context['categories'][0]['options'];

        $whatsapp = $options[0];
        $telegram = $options[1];
        $this->assertTrue($whatsapp['available']);
        $this->assertTrue($telegram['available']);
        $this->assertStringStartsWith('https://wa.me/6281234567890?text=', $whatsapp['href']);
        $this->assertStringStartsWith('https://t.me/lhieadmin?text=', $telegram['href']);

        $message = rawurldecode(parse_url($whatsapp['href'], PHP_URL_QUERY));
        $this->assertStringContainsString('Produk: Claude Pro Support Context', $message);
        $this->assertStringContainsString(route('storefront.products.show', $product), $message);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', $message);
        $this->assertStringNotContainsString('password', strtolower($message));
    }

    public function test_order_support_links_include_invoice_and_categories_without_sensitive_data(): void
    {
        config()->set('services.support.whatsapp_url', 'https://wa.me/6281111111111?source=storefront');
        config()->set('services.support.telegram_admin_url', 'https://t.me/lhiehuman');
        $order = Order::factory()->create([
            'invoice_number' => 'INV-SUPPORT-140',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'checkout_key' => 'LHIE-SECRET-RECOVERY-KEY',
        ]);

        $context = app(StorefrontSupport::class)->order($order);
        $this->assertSame([
            'QRIS / pembayaran belum terdeteksi',
            'Pembayaran gagal, expired, atau dibatalkan',
            'Data akun belum muncul',
            'Kredensial tidak valid',
            'MFA / Authenticator bermasalah',
            'Login gagal',
        ], array_column($context['categories'], 'label'));

        foreach ($context['categories'] as $category) {
            foreach ($category['options'] as $option) {
                $this->assertTrue($option['available']);
                $message = rawurldecode(parse_url($option['href'], PHP_URL_QUERY) ?: '');
                $this->assertStringContainsString('Invoice: INV-SUPPORT-140', $message);
                $this->assertStringContainsString('Kategori masalah: '.$category['label'], $message);
                $this->assertStringNotContainsString('LHIE-SECRET-RECOVERY-KEY', $message);
                $this->assertStringNotContainsString('Recovery Key', $message);
                $this->assertStringNotContainsString('password', strtolower($message));
                $this->assertStringNotContainsString('mfa secret', strtolower($message));
            }
        }
    }

    public function test_support_fallback_keeps_unconfigured_whatsapp_from_rendering_broken_links(): void
    {
        config()->set('services.support.whatsapp_url', null);
        config()->set('services.support.whatsapp_number', null);
        config()->set('services.support.telegram_admin_url', null);
        config()->set('services.support.telegram_admin_username', 'alhie02');
        config()->set('services.telegram.admin_contact_url', null);
        config()->set('services.telegram.admin_username', null);

        $context = app(StorefrontSupport::class)->general();
        $options = $context['categories'][0]['options'];

        $this->assertSame('WhatsApp Support', $options[0]['label']);
        $this->assertFalse($options[0]['available']);
        $this->assertNull($options[0]['href']);
        $this->assertSame('Telegram Admin', $options[1]['label']);
        $this->assertTrue($options[1]['available']);
        $this->assertStringStartsWith('https://t.me/alhie02?text=', $options[1]['href']);
    }

    public function test_dashboard_whatsapp_setting_takes_priority_over_environment_fallback(): void
    {
        config()->set('services.support.whatsapp_url', 'https://wa.me/6280000000000');

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'whatsapp_support_number' => '0812 9999 0000',
            'is_active' => true,
        ]);

        $context = app(StorefrontSupport::class)->general();
        $whatsapp = $context['categories'][0]['options'][0];

        $this->assertTrue($whatsapp['available']);
        $this->assertStringStartsWith('https://wa.me/6281299990000?text=', $whatsapp['href']);
    }
}
