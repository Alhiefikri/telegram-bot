<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\CheckoutMaintenanceSetting;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StorefrontSetting;
use App\Storefront\StorefrontProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\StorefrontProductScenarios;
use Tests\TestCase;

class StorefrontProductPresentationTest extends TestCase
{
    use RefreshDatabase;
    use StorefrontProductScenarios;

    protected function setUp(): void
    {
        parent::setUp();

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_available_product_presentation_is_buyable_with_page_checkout_state(): void
    {
        $product = $this->availableProduct([
            'name' => 'Claude Pro Ready',
            'description' => "Akun private siap pakai.\nGaransi login.",
            'price_amount' => 60000,
            'stock' => 8,
            'reserved_stock' => 1,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertTrue($presentation->isAvailable);
        $this->assertTrue($presentation->isBuyable);
        $this->assertSame(7, $presentation->stockAvailable);
        $this->assertSame('Stok: 7', $presentation->stockLabel);
        $this->assertSame(0, $presentation->soldQuantity);
        $this->assertSame('Terjual: 0', $presentation->soldLabel);
        $this->assertSame('Akun private siap pakai.', $presentation->descriptionSnippet);
        $this->assertSame('Beli sekarang', $presentation->cardActionLabel);
        $this->assertSame(route('storefront.products.show', $product), $presentation->productUrl);
        $this->assertSame('manual', $presentation->checkoutChoice('website_qris')?->provider);
    }

    public function test_in_stock_product_without_active_payment_setting_is_not_checkout_ready(): void
    {
        ManualPaymentSetting::query()->delete();
        $product = $this->availableProduct(['name' => 'No Checkout Provider']);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertTrue($presentation->isAvailable);
        $this->assertFalse($presentation->isBuyable);
        $this->assertFalse($presentation->checkoutReadiness->ready);
        $this->assertNull($presentation->checkoutReadiness->provider);
        $this->assertSame('Checkout belum tersedia', $presentation->statusLabel);
        $this->assertSame([], $presentation->checkoutChoices);
    }

    public function test_product_presentation_counts_paid_processable_sales_only(): void
    {
        $product = $this->availableProduct(['name' => 'ChatGPT Plus Private']);
        $paidProcessingOrder = Order::factory()->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $paidCompletedOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $pendingOrder = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);

        OrderItem::factory()->create(['order_id' => $paidProcessingOrder->id, 'product_id' => $product->id, 'quantity' => 3]);
        OrderItem::factory()->create(['order_id' => $paidCompletedOrder->id, 'product_id' => $product->id, 'quantity' => 2]);
        OrderItem::factory()->create(['order_id' => $pendingOrder->id, 'product_id' => $product->id, 'quantity' => 5]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame(5, $presentation->soldQuantity);
        $this->assertSame('Terjual: 5', $presentation->soldLabel);
    }

    public function test_unavailable_product_presentation_stays_visible_without_checkout_actions(): void
    {
        $product = $this->unavailableProduct([
            'name' => 'ChatGPT Habis',
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertFalse($presentation->isAvailable);
        $this->assertFalse($presentation->isBuyable);
        $this->assertSame('Habis', $presentation->availabilityLabel);
        $this->assertSame('Stok kosong', $presentation->stockLabel);
        $this->assertSame('Restock segera', $presentation->statusLabel);
        $this->assertSame('Stok kosong', $presentation->cardActionLabel);
        $this->assertSame([], $presentation->checkoutChoices);
        $this->assertNull($presentation->checkoutChoice('website_qris'));
        $this->assertNull($presentation->checkoutChoice('telegram_admin'));
    }

    public function test_unavailable_product_keeps_stock_status_during_maintenance(): void
    {
        CheckoutMaintenanceSetting::query()->firstOrFail()->update([
            'is_active' => true,
            'version' => 1,
            'activated_at' => now(),
        ]);
        $product = $this->unavailableProduct(['name' => 'Habis During Maintenance']);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame('Habis', $presentation->availabilityLabel);
        $this->assertSame('Stok kosong', $presentation->stockLabel);
        $this->assertSame('Restock segera', $presentation->statusLabel);
        $this->assertSame('Stok kosong', $presentation->cardActionLabel);
        $this->assertFalse($presentation->isBuyable);
    }

    public function test_available_product_exposes_only_the_website_qris_checkout_choice(): void
    {
        $product = $this->availableProduct([
            'name' => 'CapCut Pro Web',
            'stock' => 3,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $websiteQris = $presentation->checkoutChoice('website_qris');

        $this->assertNotNull($websiteQris);
        $this->assertSame('Bayar via QR Manual', $websiteQris->label);
        $this->assertStringContainsString('bukti pembayaran', $websiteQris->description);
        $this->assertTrue($websiteQris->enabled);

        $this->assertNull($presentation->checkoutChoice('telegram_admin'));
    }

    public function test_unready_klikqris_hides_native_qris_checkout_choice(): void
    {
        ManualPaymentSetting::current()->update(['payment_mode' => 'klikqris']);
        config()->set('services.klikqris.sandbox_enabled', false);
        config()->set('services.klikqris.production_enabled', false);
        $product = $this->availableProduct(['name' => 'KlikQRIS Not Ready']);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertFalse($presentation->checkoutReadiness->ready);
        $this->assertSame('klikqris', $presentation->checkoutReadiness->provider);
        $this->assertSame('QRIS otomatis belum siap', $presentation->checkoutReadiness->label);
        $this->assertFalse($presentation->isBuyable);
        $this->assertSame([], $presentation->checkoutChoices);
        $this->assertStringContainsString('QRIS otomatis sedang belum siap', $presentation->checkoutReadiness->notice);
    }

    public function test_ready_klikqris_uses_native_qris_copy(): void
    {
        KlikQrisSandboxScenarios::configureReadySandbox();
        ManualPaymentSetting::current()->update(['payment_mode' => 'klikqris']);
        $product = $this->availableProduct(['name' => 'KlikQRIS Ready']);

        $presentation = app(StorefrontProductPresenter::class)->present($product);
        $websiteQris = $presentation->checkoutChoice('website_qris');

        $this->assertTrue($presentation->checkoutReadiness->ready);
        $this->assertNotNull($websiteQris);
        $this->assertSame('Bayar via QRIS', $websiteQris->label);
        $this->assertStringContainsString('QRIS otomatis dibuat setelah checkout', $websiteQris->description);
    }

    public function test_ready_sakurupiah_uses_native_qris_copy(): void
    {
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'sandbox-id',
            'sandbox_api_key' => 'sandbox-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
        Http::fake(fn () => Http::response([
            'status' => '200',
            'data' => [[
                'kode' => 'QRISC',
                'nama' => 'QRISC Custom Merchant',
                'minimal' => 200,
                'maksimal' => 20_000_000,
                'tipe' => 'DIRECT',
                'status' => 'Aktif',
                'addition' => ['default_expired' => 24],
            ]],
        ], 200));
        ManualPaymentSetting::current()->update(['payment_mode' => 'sakurupiah']);
        $product = $this->availableProduct(['name' => 'Sakurupiah Ready']);

        $presentation = app(StorefrontProductPresenter::class)->present($product);
        $websiteQris = $presentation->checkoutChoice('website_qris');

        $this->assertTrue($presentation->checkoutReadiness->ready);
        $this->assertSame('sakurupiah', $presentation->checkoutReadiness->provider);
        $this->assertSame('QRIS otomatis', $presentation->checkoutReadiness->label);
        $this->assertNotNull($websiteQris);
        $this->assertSame('Bayar via QRIS', $websiteQris->label);
        $this->assertStringContainsString('QRIS otomatis dibuat setelah checkout', $websiteQris->description);
        $this->assertSame('sakurupiah', $websiteQris->provider);
    }

    public function test_binance_choice_exposes_only_configured_binance_pay_id_rail(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::current()->update(['binance_pay_enabled' => true]);
        $product = $this->availableProduct([
            'name' => 'Binance Pay Product',
            'usdt_price_amount' => 2_500_000,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame(2_500_000, $presentation->usdtPriceAmount);
        $this->assertNotNull($presentation->checkoutChoice('website_binance_pay'));
        $this->assertNotNull($presentation->checkoutChoice('website_binance_pay_id'));
        $this->assertNull($presentation->checkoutChoice('website_usdt_bep20'));
        $this->assertSame([
            'website_qris',
            'website_binance_pay',
            'website_binance_pay_id',
        ], array_keys($presentation->checkoutChoices));
        $this->assertSame('Bayar dengan USDT', $presentation->checkoutChoice('website_binance_pay')->label);
        $this->assertSame('Bayar dengan Binance Pay', $presentation->checkoutChoice('website_binance_pay_id')->label);
        $this->assertStringContainsString(
            'Binance Pay ID',
            $presentation->checkoutChoice('website_binance_pay_id')->description,
        );
    }

    public function test_stock_description_and_cover_image_state_are_prepared_consistently(): void
    {
        $product = $this->availableProduct([
            'name' => 'Canva Premium',
            'icon' => '🎨',
            'description' => "\n\nDesain premium siap pakai.\nDetail lain.",
            'image_path' => 'products/canva.jpg',
            'stock' => 5,
            'reserved_stock' => 2,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame(3, $presentation->stockAvailable);
        $this->assertSame('Desain premium siap pakai.', $presentation->descriptionSnippet);
        $this->assertSame('/storage/products/canva.jpg', $presentation->cover->imageUrl);
        $this->assertSame('🎨', $presentation->cover->icon);
        $this->assertSame('PRODUK DIGITAL', $presentation->cover->theme['desc']);
        $this->assertTrue($presentation->cover->hasImage());
    }

    public function test_product_icon_is_used_before_placeholder_when_no_custom_image_exists(): void
    {
        $product = $this->availableProduct([
            'name' => 'Produk Dinamis',
            'icon' => '🧩',
            'image_path' => null,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertNull($presentation->cover->imageUrl);
        $this->assertSame('🧩', $presentation->cover->icon);
        $this->assertSame('PRODUK DIGITAL', $presentation->cover->theme['desc']);
    }

    public function test_product_without_custom_image_uses_default_placeholder(): void
    {
        $product = $this->availableProduct(['image_path' => null]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame(config('storefront.product_placeholder'), $presentation->cover->imageUrl);
        $this->assertTrue($presentation->cover->hasImage());
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'image_path' => null,
        ]);
    }

    public function test_product_without_custom_image_uses_dashboard_placeholder_image(): void
    {
        Storage::fake('public');
        $path = 'storefront/placeholders/dashboard.webp';
        Storage::disk('public')->put($path, 'placeholder');
        StorefrontSetting::query()->firstOrFail()->update([
            'product_placeholder_image_path' => $path,
        ]);
        $product = $this->availableProduct(['image_path' => null]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertSame('/storage/'.$path, $presentation->cover->imageUrl);
        $this->assertTrue($presentation->cover->hasImage());
    }
}
