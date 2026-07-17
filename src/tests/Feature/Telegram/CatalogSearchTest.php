<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramProductNotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Support\TelegramBotTestSupport;

class CatalogSearchTest extends TelegramBotTestSupport
{
    public function test_start_creates_customer_and_shows_language_picker_before_welcome(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(11, '/start', ['first_name' => 'Ali', 'username' => 'ali']))->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'first_name' => 'Ali', 'username' => 'ali']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pilih bahasa')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '🇮🇩 Bahasa Indonesia'
            && ($r['reply_markup']['inline_keyboard'][0][1]['text'] ?? null) === '🇬🇧 English'
            && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'locale:id'
            && ($r['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'locale:en');
    }

    public function test_start_with_sales_proof_deep_link_shows_welcome_instead_of_command_fallback(): void
    {
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(110, '/start from_channel'))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'selamat datang di LHIE STORE'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Aku belum paham perintah itu'));
    }

    public function test_indonesian_welcome_uses_banner_and_indonesian_fallback_copy(): void
    {
        Carbon::setTestNow('2026-07-04 09:00:00');
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'first_name' => ' ', 'locale' => 'id']);
        $this->fakeTelegramHttp();

        app(TelegramMenuIntents::class)->sendWelcome($customer, '12345');

        $recorded = Http::recorded();
        $welcomePhotoIndex = $recorded->search(fn (array $record) => str_ends_with($record[0]->url(), '/sendPhoto')
            && $record[0]['photo'] === 'banner-file-id');
        $welcomeMessageIndex = $recorded->search(fn (array $record) => str_ends_with($record[0]->url(), '/sendMessage')
            && str_contains((string) ($record[0]['text'] ?? ''), 'Tempat belanja produk digital'));

        $this->assertIsInt($welcomePhotoIndex);
        $this->assertIsInt($welcomeMessageIndex);
        $welcomeText = (string) ($recorded->get($welcomeMessageIndex)[0]['text'] ?? '');
        $this->assertStringContainsString('Selamat pagi, Kak — selamat datang di LHIE STORE', $welcomeText);
        $this->assertStringNotContainsString('welcome', strtolower($welcomeText));
        $this->assertStringNotContainsString('there', strtolower($welcomeText));
        $this->assertStringNotContainsString('BANNER PLACEHOLDER', $welcomeText);
        $this->assertStringNotContainsString('[BANNER:', $welcomeText);
        $this->assertLessThan($welcomeMessageIndex, $welcomePhotoIndex);
    }

    public function test_english_start_sends_the_welcome_banner_and_english_actions(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(111, '/start', ['first_name' => 'Ayu']))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto') && $request['photo'] === 'banner-file-id');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Browse products')
            && ($request['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '🛍️ Browse Products');
    }

    public function test_english_catalog_product_and_quantity_choices_use_english_customer_copy(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $category = Category::factory()->create(['name' => 'Streaming']);
        $product = Product::factory()->create([
            'name' => 'Netflix Premium',
            'category_id' => $category->id,
            'description' => 'Merchant-authored product copy.',
            'price_amount' => 25000,
            'is_active' => true,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(112, '/products'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(113, 'cb-english-product', 'product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(114, 'cb-english-qris', 'buy:qris:'.$product->id))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Browse Products')
            && ! str_contains((string) $request['text'], 'Lihat Produk'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Netflix Premium')
            && str_contains((string) $request['text'], '💰 Price: Rp25.000')
            && str_contains((string) $request['text'], '✅ Status: Available')
            && str_contains((string) $request['text'], '📦 Stock: 2')
            && str_contains((string) $request['text'], '📝 Product details')
            && ! str_contains((string) $request['text'], 'Harga:')
            && ! str_contains((string) $request['text'], 'Status: Ready')
            && ! str_contains((string) $request['text'], 'Status: Tersedia')
            && ! str_contains((string) $request['text'], 'Detail produk'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], '🔢 Choose quantity')
            && str_contains((string) $request['text'], '✅ Available stock: 2')
            && str_contains((string) $request['text'], '💳 QRIS: Rp25.000 / item')
            && ! str_contains((string) $request['text'], ' per item')
            && ! str_contains((string) $request['text'], 'Pilih jumlah')
            && ! str_contains((string) $request['text'], 'Stok tersedia'));
    }

    public function test_katalog_lists_active_categories_uncategorized_and_empty_state(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'Streaming']);
        $unavailableCategory = Category::factory()->create(['name' => 'Preorder']);
        $inactiveCategory = Category::factory()->create(['name' => 'Kategori Mati', 'is_active' => false]);
        Product::factory()->create(['name' => 'Aktif', 'category_id' => $category->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Kosong Aktif', 'category_id' => $unavailableCategory->id, 'is_active' => true, 'stock' => 1, 'reserved_stock' => 1]);
        Product::factory()->create(['name' => 'Produk Kategori Mati', 'category_id' => $inactiveCategory->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'is_active' => true, 'stock' => 2, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Mati', 'is_active' => false]);

        $this->postTelegramWebhook($this->messageUpdate(12, '/produk'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Lihat Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id)
            && str_contains(json_encode($r->data()), 'category:'.$unavailableCategory->id)
            && str_contains(json_encode($r->data()), 'category:uncategorized')
            && ! str_contains(json_encode($r->data()), 'Kategori Mati')
            && ! str_contains(json_encode($r->data()), 'Mati'));

        $this->fakeTelegramHttp();
        Product::query()->delete();
        Category::query()->delete();

        $this->postTelegramWebhook($this->messageUpdate(120, '/katalog'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Produk masih kosong')
            && str_contains($r['text'], 'admin belum menambahkan produk aktif'));
    }

    public function test_menu_command_routes_through_webhook_and_sends_main_menu_keyboard(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(119, '/menu'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Menu utama')
            && ($r['reply_markup']['keyboard'][0][0]['text'] ?? null) === '🛍️ Lihat Produk'
            && ! str_contains(json_encode($r->data()), '☰ Menu')
            && ! str_contains(json_encode($r->data()), 'Akun Saya'));
    }

    public function test_lihat_produk_command_alias_opens_product_catalog(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'AI Tools']);
        Product::factory()->create(['name' => 'Gemini Pro', 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(133, '/lihat produk'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Lihat Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id));
    }

    public function test_category_callback_lists_products_and_uncategorized_virtual_category(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create(['name' => 'Games', 'description' => 'Voucher game']);
        $product = Product::factory()->create(['name' => 'Diamond', 'description' => 'Voucher MLBB instant.', 'price_amount' => 15000, 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);
        foreach (range(1, 3) as $index) {
            ProductStockItem::create(['product_id' => $product->id, 'secret' => "akun-diamond-{$index}@example.test | password: Secret-123"]);
        }
        $emptyProduct = Product::factory()->create(['name' => 'Diamond Habis', 'category_id' => $category->id, 'stock' => 1, 'reserved_stock' => 1]);
        Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'stock' => 2, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->callbackUpdate(121, 'cat1', 'category:'.$category->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(122, 'back-categories', 'back:categories'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Games')
            && str_contains($r['text'], 'Daftar Produk')
            && ! str_contains($r['text'], '1. ✅ Diamond')
            && ! str_contains($r['text'], '2. ❌ Diamond Habis')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '✨ 🛍️ Diamond | Rp15.000 | 📦 3'
            && ($r['reply_markup']['inline_keyboard'][1][0]['text'] ?? null) === '❌ 🛍️ Diamond Habis | Rp10.000 | 📦 0'
            && ! str_contains($r['text'], 'Stok 3')
            && ! str_contains($r['text'], 'Stok 0')
            && ! str_contains($r['text'], 'Voucher MLBB instant.')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'akun-diamond@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '✨ 🛍️ Diamond | Rp15.000 | 📦 3'
            && str_contains(json_encode($r->data()), 'product:'.$product->id)
            && str_contains(json_encode($r->data()), 'product:'.$emptyProduct->id)
            && str_contains(json_encode($r->data()), 'back:categories'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Lihat Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id)
            && str_contains(json_encode($r->data()), 'category:uncategorized'));
    }

    public function test_cari_without_keyword_sends_usage_instruction(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(123, '/cari'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Mau cari produk apa?')
            && str_contains($r['text'], '/cari netflix'));
    }

    public function test_cari_with_matching_keyword_lists_active_products(): void
    {
        $this->fakeTelegramHttp();
        $matching = Product::factory()->create(['name' => 'Netflix Premium', 'description' => 'Akun streaming', 'price_amount' => 25000, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $matching->id, 'secret' => 'netflix@example.test | password: Secret-456']);
        Product::factory()->create(['name' => 'Netflix Inactive', 'is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);
        $empty = Product::factory()->create(['name' => 'Netflix Habis', 'is_active' => true, 'stock' => 1, 'reserved_stock' => 1]);
        Product::factory()->create(['name' => 'Spotify Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(124, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Hasil pencarian: netflix')
            && str_contains($r['text'], '✅ Netflix Premium')
            && str_contains($r['text'], '❌ Netflix Habis')
            && str_contains($r['text'], 'Rp25.000')
            && ! str_contains($r['text'], 'stok 5')
            && ! str_contains($r['text'], 'stok 0')
            && str_contains($r['text'], 'Akun streaming')
            && ! str_contains($r['text'], 'Netflix Inactive')
            && ! str_contains($r['text'], 'Spotify Premium')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'netflix@example.test')
            && str_contains(($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? ''), 'Netflix Habis')
            && str_contains(json_encode($r->data()), 'product:'.$matching->id)
            && str_contains(json_encode($r->data()), 'product:'.$empty->id));
    }

    public function test_cari_result_can_be_selected_and_continues_existing_product_flow(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Canva Pro', 'description' => 'Desain premium siap pakai.', 'price_amount' => 25000, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        $completedOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        OrderItem::factory()->create([
            'order_id' => $completedOrder->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'unit_price' => $product->price_amount,
            'subtotal' => $product->price_amount * 2,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(125, '/cari canva'))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(126, 'cb-search-product', 'product:'.$product->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(132, 'cb-search-qris', 'buy:qris:'.$product->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Canva Pro')
            && str_contains($r['text'], '✅ Status: Tersedia')
            && ! str_contains($r['text'], 'Status: Ready')
            && str_contains($r['text'], '📝 Detail produk')
            && str_contains($r['text'], '🛒 Terjual: 2')
            && str_contains($r['text'], 'Desain premium siap pakai.')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '💳 QRIS · Rp25.000'
            && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'buy:qris:'.$product->id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], '🔢 Pilih jumlah')
            && str_contains($r['text'], '✅ Stok tersedia: 5')
            && str_contains($r['text'], '💳 QRIS: Rp25.000 per item')
            && ! str_contains($r['text'], ' / item')
            && ! str_contains(strtolower($r['text']), 'ready')
            && ! str_contains($r['text'], 'Choose quantity')
            && ! str_contains($r['text'], 'Available stock'));
    }

    public function test_cari_without_matching_keyword_sends_fallback_to_catalog(): void
    {
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(127, '/cari zoom'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'belum ketemu')
            && str_contains($r['text'], 'buka /produk')
            && str_contains(json_encode($r->data()), 'katalog'));
    }

    public function test_cari_does_not_show_inactive_products(): void
    {
        $this->fakeTelegramHttp();
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(128, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'belum ketemu'));
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
            && str_contains(json_encode($r->data()), 'belum ketemu'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }

    public function test_cari_shows_fully_reserved_products_as_unavailable(): void
    {
        $this->fakeTelegramHttp();
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 5]);

        $this->postTelegramWebhook($this->messageUpdate(131, '/cari netflix'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], '❌ Netflix Premium')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }

    public function test_product_created_and_restock_notifications_are_localized_for_each_recipient(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 7001, 'locale' => 'id']);
        Customer::factory()->create(['telegram_id' => 7002, 'locale' => 'en']);
        $created = Product::factory()->create([
            'name' => 'Notion Plus Launch',
            'description' => 'Merchant-authored launch copy.',
            'price_amount' => 30000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $restocked = Product::factory()->create([
            'name' => 'Figma Team Restock',
            'description' => 'Merchant-authored restock copy.',
            'price_amount' => 45000,
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $notifications = app(TelegramProductNotificationService::class);
        $notifications->notifyProductCreated($created);
        $notifications->notifyStockAdded($restocked, 1, 0);

        $this->assertCount(4, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $requestFor = function (int $chatId, string $productName) {
            $matches = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $chatId
                && str_contains((string) ($request['text'] ?? ''), $productName));

            $this->assertCount(1, $matches, "Expected one notification for chat {$chatId} and {$productName}.");

            return $matches->first()[0];
        };

        $createdId = $requestFor(7001, $created->name);
        $createdEn = $requestFor(7002, $created->name);
        $restockedId = $requestFor(7001, $restocked->name);
        $restockedEn = $requestFor(7002, $restocked->name);

        $this->assertStringContainsString('🆕 Produk Baru', (string) $createdId['text']);
        $this->assertStringContainsString('Produk baru sudah masuk', (string) $createdId['text']);
        $this->assertStringContainsString('💰 Harga: Rp30.000', (string) $createdId['text']);
        $this->assertStringContainsString('📦 Stok: 2', (string) $createdId['text']);
        $this->assertStringContainsString('📝 Deskripsi', (string) $createdId['text']);
        $this->assertStringContainsString('Butuh bantuan? Balas /support ya.', (string) $createdId['text']);
        $this->assertSame('👀 Lihat Produk', $createdId['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        $this->assertStringContainsString('🆕 New Product', (string) $createdEn['text']);
        $this->assertStringContainsString('💰 Price: Rp30.000', (string) $createdEn['text']);
        $this->assertStringContainsString('📦 Stock: 2', (string) $createdEn['text']);
        $this->assertStringContainsString('📝 Description', (string) $createdEn['text']);
        $this->assertStringContainsString('Need help? Reply with /support.', (string) $createdEn['text']);
        $this->assertSame('👀 View Product', $createdEn['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        $this->assertStringContainsString('✅ Stok Tersedia', (string) $restockedId['text']);
        $this->assertStringContainsString('Stok tersedia lagi 🔥', (string) $restockedId['text']);
        $this->assertStringNotContainsString('ready', strtolower((string) $restockedId['text']));
        $this->assertStringContainsString('💰 Harga: Rp45.000', (string) $restockedId['text']);
        $this->assertStringContainsString('📦 Stok: 1', (string) $restockedId['text']);
        $this->assertStringContainsString('📝 Deskripsi', (string) $restockedId['text']);
        $this->assertStringContainsString('Butuh bantuan? Balas /support ya.', (string) $restockedId['text']);
        $this->assertSame('👀 Lihat Produk', $restockedId['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        $this->assertStringContainsString('✅ Back in Stock', (string) $restockedEn['text']);
        $this->assertStringContainsString('💰 Price: Rp45.000', (string) $restockedEn['text']);
        $this->assertStringContainsString('📦 Stock: 1', (string) $restockedEn['text']);
        $this->assertStringContainsString('📝 Description', (string) $restockedEn['text']);
        $this->assertStringContainsString('Need help? Reply with /support.', (string) $restockedEn['text']);
        $this->assertSame('👀 View Product', $restockedEn['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        foreach ([$createdEn, $restockedEn] as $englishRequest) {
            $englishPayload = json_encode($englishRequest->data());
            foreach (['Produk Baru', 'Harga:', 'Stok:', 'Deskripsi', 'Butuh bantuan', 'Lihat Produk'] as $indonesianSystemCopy) {
                $this->assertStringNotContainsString($indonesianSystemCopy, $englishPayload);
            }
        }

        $this->assertNotSame($createdId['text'], $createdEn['text']);
        $this->assertNotSame($createdId['reply_markup'], $createdEn['reply_markup']);
        $this->assertNotSame($restockedId['text'], $restockedEn['text']);
        $this->assertNotSame($restockedId['reply_markup'], $restockedEn['reply_markup']);
    }

    public function test_product_restock_notification_has_localized_look_button_and_description(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 777, 'locale' => 'id']);
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
            && str_contains($r['text'], '✅ Stok Tersedia')
            && str_contains($r['text'], 'Stok tersedia lagi 🔥')
            && ! str_contains(strtolower($r['text']), 'ready')
            && str_contains($r['text'], '💰 Harga: Rp25.000')
            && str_contains($r['text'], '📦 Stok: 1')
            && str_contains($r['text'], '#NO GARANSI. #FRESH BILL.')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'chatgpt@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '👀 Lihat Produk'
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }

    public function test_product_created_notification_is_buyer_safe_and_zero_stock_restock_notification_is_not_sent(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 778, 'locale' => 'id']);
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
        ProductStockItem::create(['product_id' => $ready->id, 'secret' => 'canva-1@example.test | password: Secret-000']);
        ProductStockItem::create(['product_id' => $ready->id, 'secret' => 'canva-2@example.test | password: Secret-000']);
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
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '👀 Lihat Produk'
            && str_contains(json_encode($r->data()), 'product:'.$ready->id));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Claude Pro Reserved'));
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
