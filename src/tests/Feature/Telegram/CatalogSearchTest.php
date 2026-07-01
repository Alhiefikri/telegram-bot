<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CatalogSearchTest extends TelegramBotTestSupport
{
    public function test_start_creates_customer_and_sends_welcome(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(11, '/start', ['first_name' => 'Ali', 'username' => 'ali']))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'first_name' => 'Ali', 'username' => 'ali']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Selamat datang, Ali')
            && str_contains($r['text'], 'Belanja produk digital langsung dari Telegram')
            && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'katalog'
            && ($r['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'status'
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'Status akun'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Menu utama')
            && ($r['reply_markup']['keyboard'][0][0]['text'] ?? null) === '🛍️ Marketplace'
            && ($r['reply_markup']['keyboard'][0][1]['text'] ?? null) === '📦 Pesanan'
            && ($r['reply_markup']['keyboard'][1][0]['text'] ?? null) === '👤 Akun Saya'
            && ($r['reply_markup']['keyboard'][1][1]['text'] ?? null) === '💬 Support'
            && ! str_contains(json_encode($r->data()), 'Admin Dashboard')
            && ! str_contains(json_encode($r->data()), 'Broadcast Info'));
    }

    public function test_start_uses_welcome_banner_caption_when_configured(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(110, '/start', ['first_name' => 'Ayu']))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && $r['photo'] === 'banner-file-id'
            && str_contains($r['caption'], 'Selamat datang, Ayu')
            && str_contains($r['caption'], 'Belanja produk digital langsung dari Telegram')
            && ! str_contains($r['caption'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['caption'], '[BANNER:'));
    }

    public function test_katalog_lists_active_available_categories_uncategorized_and_empty_state(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'Streaming']);
        $inactiveCategory = Category::factory()->create(['name' => 'Kategori Mati', 'is_active' => false]);
        Product::factory()->create(['name' => 'Aktif', 'category_id' => $category->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Produk Kategori Mati', 'category_id' => $inactiveCategory->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'is_active' => true, 'stock' => 2, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Mati', 'is_active' => false]);

        $this->postTelegramWebhook($this->messageUpdate(12, '/katalog'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Katalog Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id)
            && str_contains(json_encode($r->data()), 'category:uncategorized')
            && ! str_contains(json_encode($r->data()), 'Kategori Mati')
            && ! str_contains(json_encode($r->data()), 'Mati'));

        $this->fakeTelegramHttp();
        Product::query()->delete();
        Category::query()->delete();

        $this->postTelegramWebhook($this->messageUpdate(120, '/katalog'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Katalog masih kosong')
            && str_contains($r['text'], 'Produk baru akan tampil'));
    }

    public function test_menu_command_routes_through_webhook_and_sends_main_menu_keyboard(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(119, '/menu'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Menu utama')
            && ($r['reply_markup']['keyboard'][0][0]['text'] ?? null) === '🛍️ Marketplace');
    }

    public function test_category_callback_lists_products_and_uncategorized_virtual_category(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'Games', 'description' => 'Voucher game']);
        $product = Product::factory()->create(['name' => 'Diamond', 'description' => 'Voucher MLBB instant.', 'price_amount' => 15000, 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'akun-diamond@example.test | password: Secret-123']);
        Product::factory()->create(['name' => 'Diamond Habis', 'category_id' => $category->id, 'stock' => 1, 'reserved_stock' => 1]);
        Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'stock' => 2, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(121, 'cat1', 'category:'.$category->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(122, 'back-categories', 'back:categories'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Games')
            && str_contains($r['text'], '1. ✅ Diamond')
            && str_contains($r['text'], 'Rp15.000 · 📦 Stok 3')
            && str_contains($r['text'], 'Voucher MLBB instant.')
            && ! str_contains($r['text'], 'Diamond Habis')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'akun-diamond@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '👁️ Lihat #1'
            && str_contains(json_encode($r->data()), 'product:'.$product->id)
            && str_contains(json_encode($r->data()), 'back:categories'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Katalog Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id)
            && str_contains(json_encode($r->data()), 'category:uncategorized'));
    }

    public function test_cari_without_keyword_sends_usage_instruction(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(123, '/cari'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Ketik /cari')
            && str_contains($r['text'], '/cari netflix'));
    }

    public function test_cari_with_matching_keyword_lists_active_available_products(): void
    {
        $this->fakeTelegramHttp();
        $matching = Product::factory()->create(['name' => 'Netflix Premium', 'description' => 'Akun streaming', 'price_amount' => 25000, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $matching->id, 'secret' => 'netflix@example.test | password: Secret-456']);
        Product::factory()->create(['name' => 'Netflix Inactive', 'is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Netflix Habis', 'is_active' => true, 'stock' => 1, 'reserved_stock' => 1]);
        Product::factory()->create(['name' => 'Spotify Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(124, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Hasil pencarian: netflix')
            && str_contains($r['text'], 'Netflix Premium')
            && str_contains($r['text'], 'Rp25.000 · stok 5')
            && str_contains($r['text'], 'Akun streaming')
            && ! str_contains($r['text'], 'Netflix Inactive')
            && ! str_contains($r['text'], 'Netflix Habis')
            && ! str_contains($r['text'], 'Spotify Premium')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'netflix@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '👁️ Lihat #1'
            && str_contains(json_encode($r->data()), 'product:'.$matching->id));
    }

    public function test_cari_result_can_be_selected_and_continues_existing_product_flow(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Canva Pro', 'description' => 'Desain premium siap pakai.', 'price_amount' => 25000, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(125, '/cari canva'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(126, 'cb-search-product', 'product:'.$product->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Canva Pro')
            && str_contains($r['text'], '📝 Deskripsi')
            && str_contains($r['text'], 'Desain premium siap pakai.')
            && str_contains(json_encode($r->data()), 'Beli (Rp25.000)')
            && str_contains(json_encode($r->data()), 'buy:'.$product->id));
    }

    public function test_cari_without_matching_keyword_sends_fallback_to_catalog(): void
    {
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(127, '/cari zoom'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'tidak ditemukan')
            && str_contains($r['text'], '/katalog')
            && str_contains(json_encode($r->data()), 'katalog'));
    }

    public function test_cari_does_not_show_inactive_products(): void
    {
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(128, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'tidak ditemukan'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }

    public function test_cari_matches_case_insensitively(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(129, '/cari NETFLIX'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Netflix Premium')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }

    public function test_cari_does_not_show_products_from_inactive_categories(): void
    {
        $this->fakeTelegramHttp();
        $inactiveCategory = Category::factory()->create(['name' => 'Streaming', 'is_active' => false]);
        Product::factory()->create(['name' => 'Netflix Premium', 'category_id' => $inactiveCategory->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(130, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'tidak ditemukan'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }

    public function test_cari_does_not_show_fully_reserved_products(): void
    {
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 5]);

        $this->postTelegramWebhook($this->messageUpdate(131, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'tidak ditemukan'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }


    public function test_product_ready_notification_has_look_button_and_description(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 777]);
        $product = Product::factory()->create([
            'name' => 'ChatGPT Plus 1 Bulan Fresh Bill',
            'description' => '#NO GARANSI. #FRESH BILL.',
            'price_amount' => 25000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'chatgpt@example.test | password: Secret-789']);

        app(TelegramProductNotificationService::class)->notifyStockAdded($product, 1);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'ChatGPT Plus 1 Bulan Fresh Bill')
            && str_contains($r['text'], '💰 Harga: Rp25.000')
            && str_contains($r['text'], '📦 Stok: 1')
            && str_contains($r['text'], '#NO GARANSI. #FRESH BILL.')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'chatgpt@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '👁️ Lihat'
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }

    public function test_product_created_notification_and_zero_stock_notification_are_buyer_safe(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 778]);
        $ready = Product::factory()->create([
            'name' => 'Canva Pro Baru',
            'description' => 'Desain premium siap pakai.',
            'price_amount' => 30000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $reserved = Product::factory()->create([
            'name' => 'Claude Pro Reserved',
            'description' => 'Akun akan ready lagi segera.',
            'price_amount' => 45000,
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        ProductStockItem::create(['product_id' => $ready->id, 'secret' => 'canva@example.test | password: Secret-000']);
        ProductStockItem::create(['product_id' => $reserved->id, 'secret' => 'claude@example.test | password: Secret-111']);

        app(TelegramProductNotificationService::class)->notifyProductCreated($ready);
        app(TelegramProductNotificationService::class)->notifyStockAdded($reserved, 1);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Produk baru sudah masuk')
            && str_contains($r['text'], 'Canva Pro Baru')
            && str_contains($r['text'], '💰 Harga: Rp30.000')
            && str_contains($r['text'], '📦 Stok: 2')
            && str_contains($r['text'], 'Desain premium siap pakai.')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'canva@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '👁️ Lihat'
            && str_contains(json_encode($r->data()), 'product:'.$ready->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Claude Pro Reserved')
            && str_contains($r['text'], '💰 Harga: Rp45.000')
            && str_contains($r['text'], '📦 Stok: 0')
            && str_contains($r['text'], 'Akun akan ready lagi segera.')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'claude@example.test')
            && ! str_contains(json_encode($r->data()), '👁️ Lihat')
            && ! str_contains(json_encode($r->data()), 'product:'.$reserved->id));
    }
    public function test_cari_can_match_active_category_name(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'Streaming', 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'category_id' => $category->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(132, '/cari streaming'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Netflix Premium')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }
}
