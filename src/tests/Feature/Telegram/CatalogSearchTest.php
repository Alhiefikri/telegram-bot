<?php

namespace Tests\Feature\Telegram;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CatalogSearchTest extends TelegramBotTestSupport
{
    public function test_start_creates_customer_and_sends_welcome(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'AI Tools']);
        Product::factory()->create(['category_id' => $category->id, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(11, '/start', ['first_name' => 'Ali', 'username' => 'ali']))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'first_name' => 'Ali', 'username' => 'ali']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Katalog Produk'));
    }

    public function test_katalog_lists_active_available_categories_with_inline_keyboard(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'Streaming']);
        Product::factory()->create(['name' => 'Aktif', 'category_id' => $category->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Mati', 'is_active' => false]);

        $this->postTelegramWebhook($this->messageUpdate(12, '/katalog'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'category:'.$category->id
            && ! str_contains(json_encode($r->data()), 'Mati'));
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
        $product = Product::factory()->create(['name' => 'Diamond', 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'stock' => 2, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(121, 'cat1', 'category:'.$category->id))->assertOk();
        $this->postTelegramWebhook($this->messageUpdate(122, '/katalog'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Games')
            && str_contains($r['text'], 'Diamond')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
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
        $matching = Product::factory()->create(['name' => 'Netflix Premium', 'description' => 'Akun streaming', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Spotify Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(124, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Hasil pencarian: netflix')
            && str_contains($r['text'], 'Netflix Premium')
            && ! str_contains($r['text'], 'Spotify Premium')
            && str_contains(json_encode($r->data()), 'product:'.$matching->id));
    }

    public function test_cari_result_can_be_selected_and_continues_existing_product_flow(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Canva Pro', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(125, '/cari canva'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(126, 'cb-search-product', 'product:'.$product->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Canva Pro')
            && str_contains($r['text'], 'Pilih jumlah')
            && str_contains(json_encode($r->data()), 'qty:'.$product->id.':1'));
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
