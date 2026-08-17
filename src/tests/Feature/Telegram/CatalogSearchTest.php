<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
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
            && str_contains($r['text'], 'Choose your language')
            && count($r['reply_markup']['inline_keyboard'] ?? []) === 1
            && count($r['reply_markup']['inline_keyboard'][0] ?? []) === 3
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '🇮🇩 Bahasa Indonesia'
            && ($r['reply_markup']['inline_keyboard'][0][1]['text'] ?? null) === '🇬🇧 English'
            && ($r['reply_markup']['inline_keyboard'][0][2]['text'] ?? null) === '🇲🇾 Bahasa Melayu'
            && ($r['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null) === 'locale:id'
            && ($r['reply_markup']['inline_keyboard'][0][1]['callback_data'] ?? null) === 'locale:en'
            && ($r['reply_markup']['inline_keyboard'][0][2]['callback_data'] ?? null) === 'locale:ms');
    }

    public function test_start_with_sales_proof_deep_link_shows_welcome_instead_of_command_fallback(): void
    {
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(110, '/start from_channel'))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'selamat datang di LHIE STORE'));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Aku belum paham perintah itu'));
    }

    public function test_start_with_product_deep_link_opens_that_product_checkout_options(): void
    {
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $this->fakeTelegramHttp();
        $product = Product::factory()->create([
            'name' => 'Produk Deep Link',
            'description' => 'Produk dari tautan Telegram.',
            'price_amount' => 44000,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(112, '/start product_'.$product->id))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Produk Deep Link')
            && str_contains((string) $request['text'], 'Detail produk')
            && str_contains(json_encode($request->data()), 'buy:qris:'.$product->id));
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'selamat datang di LHIE STORE'));
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
        $welcomeMessageIndex = $recorded->search(fn (array $record) => str_ends_with($record[0]->url(), '/sendPhoto')
            && str_contains((string) ($record[0]['caption'] ?? ''), 'Tempat belanja produk digital'));

        $this->assertIsInt($welcomePhotoIndex);
        $this->assertIsInt($welcomeMessageIndex);
        $welcomeText = (string) ($recorded->get($welcomeMessageIndex)[0]['caption'] ?? '');
        $this->assertStringContainsString('Selamat pagi, Kak — selamat datang di LHIE STORE', $welcomeText);
        $this->assertStringNotContainsString('👋', $welcomeText);
        $this->assertStringNotContainsString('welcome', strtolower($welcomeText));
        $this->assertStringNotContainsString('there', strtolower($welcomeText));
        $this->assertStringNotContainsString('BANNER PLACEHOLDER', $welcomeText);
        $this->assertStringNotContainsString('[BANNER:', $welcomeText);
        $this->assertSame($welcomePhotoIndex, $welcomeMessageIndex);
    }

    public function test_english_start_sends_the_welcome_banner_and_english_actions(): void
    {
        config()->set('services.telegram.welcome_banner_url', 'banner-file-id');
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(111, '/start', ['first_name' => 'Ayu']))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && $request['photo'] === 'banner-file-id'
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'Browse products')
            && ($request->data()['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '🛍️ Browse Products');
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
            && str_contains((string) $request['text'], 'Product List')
            && ! str_contains((string) $request['text'], 'Daftar Produk'));
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
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
            && str_contains($r['text'], 'Belum ada produk yang bisa ditampilkan sekarang')
            && ! str_contains($r['text'], 'admin'));
    }

    public function test_menu_command_routes_through_webhook_and_sends_main_menu_keyboard(): void
    {
        $this->fakeTelegramHttp();

        $this->postTelegramWebhook($this->messageUpdate(119, '/menu'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Main menu')
            && ! str_contains($r['text'], '🚀')
            && ($r['reply_markup']['keyboard'][0][0]['text'] ?? null) === '🛍️ Browse Products'
            && ! str_contains(json_encode($r->data()), '☰ Menu')
            && ! str_contains(json_encode($r->data()), 'Akun Saya'));
    }

    public function test_lihat_produk_command_alias_opens_product_catalog(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $category = Category::factory()->create(['name' => 'AI Tools']);
        $otherCategory = Category::factory()->create(['name' => 'Streaming']);
        Product::factory()->create(['name' => 'Gemini Pro', 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Netflix', 'category_id' => $otherCategory->id, 'stock' => 3, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(133, '/lihat produk'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Lihat Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id));
    }

    public function test_katalog_with_single_category_skips_category_selection(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $category = Category::factory()->create(['name' => 'AI Tools']);
        $product = Product::factory()->create(['name' => 'Gemini Pro', 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(135, '/produk'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Daftar Produk')
            && str_contains($r['text'], 'Kategori: AI Tools')
            && str_contains(json_encode($r->data()), 'product:'.$product->id)
            && ! str_contains(json_encode($r->data()), 'back:categories'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pilih kategori yang kamu mau'));
    }

    public function test_katalog_with_only_uncategorized_products_skips_category_selection(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'stock' => 2, 'reserved_stock' => 0]);

        $this->postTelegramWebhook($this->messageUpdate(136, '/produk'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Daftar Produk')
            && str_contains($r['text'], 'Kategori: 📂 Lainnya')
            && str_contains(json_encode($r->data()), 'product:'.$product->id)
            && ! str_contains(json_encode($r->data()), 'back:categories'));
    }

    public function test_category_callback_lists_products_and_uncategorized_virtual_category(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'is_active' => true]);
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $category = Category::factory()->create(['name' => 'Games', 'description' => 'Voucher game']);
        $product = Product::factory()->create(['name' => 'Diamond', 'description' => 'Voucher MLBB instant.', 'price_amount' => 15000, 'usdt_price_amount' => 1_250_000, 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);
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
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '✨ 🛍️ Diamond · Rp 15.000 · 1.25 USDT'
            && ($r['reply_markup']['inline_keyboard'][1][0]['text'] ?? null) === '❌ 🛍️ Diamond Habis · Rp 10.000'
            && ! str_contains($r['text'], 'Stok 3')
            && ! str_contains($r['text'], 'Stok 0')
            && ! str_contains($r['text'], 'Voucher MLBB instant.')
            && ! str_contains($r['text'], 'BANNER PLACEHOLDER')
            && ! str_contains($r['text'], '[BANNER:')
            && ! str_contains($r['text'], 'akun-diamond@example.test')
            && ($r['reply_markup']['inline_keyboard'][0][0]['text'] ?? null) === '✨ 🛍️ Diamond · Rp 15.000 · 1.25 USDT'
            && str_contains(json_encode($r->data()), 'product:'.$product->id)
            && str_contains(json_encode($r->data()), 'product:'.$emptyProduct->id)
            && str_contains(json_encode($r->data()), 'back:categories'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Lihat Produk')
            && str_contains(json_encode($r->data()), 'category:'.$category->id)
            && str_contains(json_encode($r->data()), 'category:uncategorized'));
    }

    public function test_unavailable_and_empty_category_messages_follow_customer_error_voice(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $unavailable = Category::factory()->create(['name' => 'Unavailable']);
        $empty = Category::factory()->create(['name' => 'Empty']);
        $unavailable->delete();

        $this->postTelegramWebhook($this->callbackUpdate(123, 'unavailable-category', 'category:'.$unavailable->id))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(124, 'empty-category', 'category:'.$empty->id))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['text'] === 'Kategori ini sudah tidak tersedia. Coba pilih kategori lain.');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Belum ada produk di kategori ini')
            && ! str_contains($r['text'], '━━━━━━━━━━━━━━'));
    }

    public function test_search_product_button_shows_usdt_and_respects_telegram_label_limit(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => true, 'is_active' => true]);
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create([
            'name' => str_repeat('Premium Account ', 8),
            'description' => 'Ready for checkout.',
            'price_amount' => 35000,
            'usdt_price_amount' => 1_500_000,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->postTelegramWebhook($this->messageUpdate(133, '/cari premium'))->assertOk();

        $request = Http::recorded()
            ->map(fn (array $record) => $record[0])
            ->first(fn ($request) => str_ends_with($request->url(), '/sendMessage')
                && str_contains((string) $request['text'], 'Hasil pencarian: premium'));
        $buttonText = $request['reply_markup']['inline_keyboard'][0][0]['text'] ?? '';

        $this->assertSame('✨ 🛍️ Premium Account Premium Account P... · Rp 35.000 · 1.5 USDT', $buttonText);
        $this->assertLessThanOrEqual(64, mb_strlen($buttonText));
        $this->assertSame('product:'.$product->id, $request['reply_markup']['inline_keyboard'][0][0]['callback_data'] ?? null);
    }

    public function test_category_product_buttons_hide_usdt_when_payment_is_unavailable(): void
    {
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'binance_pay_enabled' => false, 'is_active' => true]);
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $category = Category::factory()->create(['name' => 'AI Tools']);
        Product::factory()->create(['name' => 'Binance Disabled', 'category_id' => $category->id, 'usdt_price_amount' => 1_500_000, 'stock' => 1, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Price Missing', 'category_id' => $category->id, 'usdt_price_amount' => null, 'stock' => 1, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Out of Stock', 'category_id' => $category->id, 'usdt_price_amount' => 1_500_000, 'stock' => 1, 'reserved_stock' => 1]);

        $this->postTelegramWebhook($this->callbackUpdate(134, 'cat-usdt-gates', 'category:'.$category->id))->assertOk();

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Daftar Produk')
            && ! str_contains(json_encode($request['reply_markup']['inline_keyboard']), 'USDT'));
    }

    public function test_cari_without_keyword_sends_usage_instruction(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);

        $this->postTelegramWebhook($this->messageUpdate(123, '/cari'))->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Mau cari produk apa?')
            && str_contains($r['text'], '/cari netflix'));
    }

    public function test_cari_with_matching_keyword_lists_active_products(): void
    {
        $this->fakeTelegramHttp();
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
        Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
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
        Customer::factory()->create(['telegram_id' => 7003, 'locale' => 'ms']);
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

        $this->assertCount(6, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')));
        $requestFor = function (int $chatId, string $productName) {
            $matches = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $chatId
                && str_contains((string) ($request['text'] ?? ''), $productName));

            $this->assertCount(1, $matches, "Expected one notification for chat {$chatId} and {$productName}.");

            return $matches->first()[0];
        };

        $createdId = $requestFor(7001, $created->name);
        $createdEn = $requestFor(7002, $created->name);
        $createdMs = $requestFor(7003, $created->name);
        $restockedId = $requestFor(7001, $restocked->name);
        $restockedEn = $requestFor(7002, $restocked->name);
        $restockedMs = $requestFor(7003, $restocked->name);

        $this->assertStringContainsString('🆕 Produk Baru', (string) $createdId['text']);
        $this->assertStringContainsString('Produk baru sudah masuk', (string) $createdId['text']);
        $this->assertStringContainsString('💰 Harga: Rp30.000', (string) $createdId['text']);
        $this->assertStringContainsString('📦 Stok: 2', (string) $createdId['text']);
        $this->assertStringNotContainsString('👀', (string) $createdId['text']);
        $this->assertStringContainsString('📝 Deskripsi', (string) $createdId['text']);
        $this->assertStringContainsString('Butuh bantuan? Balas /support ya.', (string) $createdId['text']);
        $this->assertSame('👀 Lihat Produk', $createdId['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        $this->assertStringContainsString('🆕 New Product', (string) $createdEn['text']);
        $this->assertStringContainsString('💰 Price: Rp30.000', (string) $createdEn['text']);
        $this->assertStringContainsString('📦 Stock: 2', (string) $createdEn['text']);
        $this->assertStringContainsString('📝 Description', (string) $createdEn['text']);
        $this->assertStringContainsString('Need help? Reply with /support.', (string) $createdEn['text']);
        $this->assertSame('👀 View Product', $createdEn['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertStringNotContainsString('👀', (string) $createdEn['text']);
        $this->assertStringContainsString('A new product just arrived', (string) $createdEn['text']);

        $this->assertStringContainsString('🆕 Produk Baharu', (string) $createdMs['text']);
        $this->assertStringContainsString('Produk baharu baru sahaja tiba', (string) $createdMs['text']);
        $this->assertStringContainsString('💰 Harga: Rp30.000', (string) $createdMs['text']);
        $this->assertStringContainsString('📦 Stok: 2', (string) $createdMs['text']);
        $this->assertStringContainsString('📝 Penerangan', (string) $createdMs['text']);
        $this->assertStringContainsString('Perlukan bantuan? Balas /support.', (string) $createdMs['text']);
        $this->assertSame('👀 Lihat Produk', $createdMs['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertStringNotContainsString('Produk Baru', (string) $createdMs['text']);
        $this->assertStringNotContainsString('New Product', (string) $createdMs['text']);

        $this->assertStringContainsString('✅ Stok Tersedia', (string) $restockedId['text']);
        $this->assertStringContainsString('Stok tersedia lagi', (string) $restockedId['text']);
        $this->assertStringNotContainsString('ready', strtolower((string) $restockedId['text']));
        $this->assertStringContainsString('💰 Harga: Rp45.000', (string) $restockedId['text']);
        $this->assertStringContainsString('📦 Stok: 1', (string) $restockedId['text']);
        $this->assertStringContainsString('📝 Deskripsi', (string) $restockedId['text']);
        $this->assertStringNotContainsString('🔥', (string) $restockedEn['text']);
        $this->assertStringContainsString('Back in stock', (string) $restockedEn['text']);
        $this->assertStringNotContainsString('🔥', (string) $restockedId['text']);
        $this->assertStringContainsString('Butuh bantuan? Balas /support ya.', (string) $restockedId['text']);
        $this->assertSame('👀 Lihat Produk', $restockedId['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        $this->assertStringContainsString('✅ Back in Stock', (string) $restockedEn['text']);
        $this->assertStringContainsString('💰 Price: Rp45.000', (string) $restockedEn['text']);
        $this->assertStringContainsString('📦 Stock: 1', (string) $restockedEn['text']);
        $this->assertStringContainsString('📝 Description', (string) $restockedEn['text']);
        $this->assertStringContainsString('Need help? Reply with /support.', (string) $restockedEn['text']);
        $this->assertSame('👀 View Product', $restockedEn['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);

        $this->assertStringContainsString('✅ Stok Tersedia Semula', (string) $restockedMs['text']);
        $this->assertStringContainsString('Stok tersedia semula', (string) $restockedMs['text']);
        $this->assertStringContainsString('💰 Harga: Rp45.000', (string) $restockedMs['text']);
        $this->assertStringContainsString('📦 Stok: 1', (string) $restockedMs['text']);
        $this->assertStringContainsString('📝 Penerangan', (string) $restockedMs['text']);
        $this->assertStringContainsString('Perlukan bantuan? Balas /support.', (string) $restockedMs['text']);
        $this->assertSame('👀 Lihat Produk', $restockedMs['reply_markup']['inline_keyboard'][0][0]['text'] ?? null);
        $this->assertStringNotContainsString('Stok tersedia lagi', (string) $restockedMs['text']);

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
        $this->assertNotSame($createdEn['text'], $createdMs['text']);
        $this->assertNotSame($restockedEn['text'], $restockedMs['text']);
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
            && str_contains($r['text'], 'Stok tersedia lagi')
            && ! str_contains($r['text'], '🔥')
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

    public function test_catalog_and_search_copy_follow_each_locale_without_translating_merchant_content(): void
    {
        $this->fakeTelegramHttp();
        $category = Category::factory()->create([
            'name' => 'Streaming',
            'description' => 'Merchant category copy / keep exact.',
        ]);
        $otherCategory = Category::factory()->create(['name' => 'Design']);
        $product = Product::factory()->create([
            'name' => 'Netflix Premium™',
            'description' => 'Merchant product copy / keep exact.',
            'category_id' => $category->id,
            'price_amount' => 25000,
            'stock' => 3,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Canva Pro',
            'category_id' => $otherCategory->id,
            'stock' => 2,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $locales = [
            'id' => ['chat_id' => 45101, 'catalog' => 'Lihat Produk', 'list' => 'Daftar Produk', 'details' => 'Detail produk', 'quantity' => 'Pilih jumlah', 'search' => 'Hasil pencarian'],
            'en' => ['chat_id' => 45102, 'catalog' => 'Browse Products', 'list' => 'Product List', 'details' => 'Product details', 'quantity' => 'Choose quantity', 'search' => 'Search results'],
            'ms' => ['chat_id' => 45103, 'catalog' => 'Lihat Produk', 'list' => 'Senarai Produk', 'details' => 'Butiran produk', 'quantity' => 'Pilih kuantiti', 'search' => 'Hasil carian'],
        ];

        foreach ($locales as $index => $locale) {
            Customer::factory()->create(['telegram_id' => $locale['chat_id'], 'locale' => $index]);
            $from = ['id' => $locale['chat_id'], 'first_name' => 'Locale Tester'];

            $this->postTelegramWebhook($this->messageUpdate(45110 + $locale['chat_id'], '/products', $from, $locale['chat_id']))->assertOk();
            $this->postTelegramWebhook($this->callbackUpdate(45120 + $locale['chat_id'], 'category-'.$index, 'category:'.$category->id, $from, $locale['chat_id']))->assertOk();
            $this->postTelegramWebhook($this->callbackUpdate(45130 + $locale['chat_id'], 'product-'.$index, 'product:'.$product->id, $from, $locale['chat_id']))->assertOk();
            $this->postTelegramWebhook($this->callbackUpdate(45140 + $locale['chat_id'], 'buy-'.$index, 'buy:'.$product->id, $from, $locale['chat_id']))->assertOk();
            $this->postTelegramWebhook($this->messageUpdate(45150 + $locale['chat_id'], '/search netflix', $from, $locale['chat_id']))->assertOk();

            $payloads = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage')
                && (string) ($request['chat_id'] ?? '') === (string) $locale['chat_id'])
                ->map(fn (array $record): array => $record[0]->data());
            $copy = $payloads->map(fn (array $payload): string => (string) ($payload['text'] ?? '')."\n".json_encode($payload['reply_markup'] ?? []))->implode("\n");

            $this->assertStringContainsString($locale['catalog'], $copy);
            $this->assertStringContainsString($locale['list'], $copy);
            $this->assertStringContainsString($locale['details'], $copy);
            $this->assertStringContainsString($locale['quantity'], $copy);
            $this->assertStringContainsString($locale['search'], $copy);
            $this->assertStringContainsString('Netflix Premium™', $copy);
            $this->assertStringContainsString('Merchant category copy / keep exact.', $copy);
            $this->assertStringContainsString('Merchant product copy / keep exact.', $copy);
            $this->assertStringContainsString('Rp25.000', $copy);

            if ($index === 'en') {
                $this->assertStringNotContainsString('Daftar Produk', $copy);
                $this->assertStringNotContainsString('Detail produk', $copy);
                $this->assertStringNotContainsString('Pilih jumlah', $copy);
                $this->assertStringNotContainsString('Hasil pencarian', $copy);
            }

            if ($index === 'ms') {
                $this->assertStringNotContainsString('Daftar Produk', $copy);
                $this->assertStringNotContainsString('Detail produk', $copy);
                $this->assertStringNotContainsString('Pilih jumlah', $copy);
                $this->assertStringNotContainsString('Hasil pencarian', $copy);
                $this->assertStringNotContainsString('Product List', $copy);
                $this->assertStringNotContainsString('Product details', $copy);
                $this->assertStringNotContainsString('Choose quantity', $copy);
                $this->assertStringNotContainsString('Search results', $copy);
            }
        }
    }
}
