<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramUpdate;
use App\Services\CartService;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase6TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telegram', ['token' => 'bot-token', 'webhook_secret' => 'secret', 'api_base_url' => 'https://api.telegram.org', 'default_payment_method' => 'BRIVA']);
        config()->set('services.pakasir', ['slug' => 'sedulur-digital', 'api_key' => 'pakasir-key', 'base_url' => 'https://app.pakasir.test', 'redirect_url' => null, 'qris_only' => false]);
        config()->set('queue.default', 'sync');
    }

    public function test_webhook_secret_stores_update_and_dispatches_job(): void
    {
        Bus::fake();

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(1, '/start'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(2, '/start'), ['X-Telegram-Bot-Api-Secret-Token' => 'bad'])->assertForbidden();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatched(ProcessTelegramUpdate::class);
    }

    public function test_duplicate_webhook_post_dispatches_only_once_and_invalid_payload_is_rejected(): void
    {
        Bus::fake();
        $payload = $this->messageUpdate(101, '/start');

        $this->postJson('/api/webhook/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', ['update_id' => 102, 'edited_message' => []], ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertUnprocessable();

        $this->assertSame(1, TelegramUpdate::count());
        Bus::assertDispatchedTimes(ProcessTelegramUpdate::class, 1);
    }

    public function test_duplicate_update_is_not_processed_twice(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $payload = $this->messageUpdate(10, '/start');
        $this->postJson('/api/webhook/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $processedAt = TelegramUpdate::first()->processed_at;
        $this->postJson('/api/webhook/telegram', $payload, ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertNotNull($processedAt);
        $this->assertEquals($processedAt, TelegramUpdate::first()->processed_at);
        Http::assertSentCount(2);
    }

    public function test_duplicate_direct_job_does_not_send_twice(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $update = TelegramUpdate::create(['update_id' => 103, 'payload' => $this->messageUpdate(103, '/start'), 'created_at' => now()]);

        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        Http::assertSentCount(2);
    }

    public function test_start_creates_customer_and_sends_welcome(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $category = Category::factory()->create(['name' => 'AI Tools']);
        Product::factory()->create(['category_id' => $category->id, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(11, '/start', ['first_name' => 'Ali', 'username' => 'ali']), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertDatabaseHas('customers', ['telegram_id' => 12345, 'first_name' => 'Ali', 'username' => 'ali']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Katalog Produk'));
    }

    public function test_katalog_lists_active_available_categories_with_inline_keyboard(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $category = Category::factory()->create(['name' => 'Streaming']);
        Product::factory()->create(['name' => 'Aktif', 'category_id' => $category->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Mati', 'is_active' => false]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(12, '/katalog'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['reply_markup']['inline_keyboard'][0][0]['callback_data'] === 'category:'.$category->id
            && ! str_contains(json_encode($r->data()), 'Mati'));
    }

    public function test_category_callback_lists_products_and_uncategorized_virtual_category(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $category = Category::factory()->create(['name' => 'Games', 'description' => 'Voucher game']);
        $product = Product::factory()->create(['name' => 'Diamond', 'category_id' => $category->id, 'stock' => 3, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Tanpa Kategori', 'category_id' => null, 'stock' => 2, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(121, 'cat1', 'category:'.$category->id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(122, '/katalog'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Games')
            && str_contains($r['text'], 'Diamond')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'category:uncategorized'));
    }

    public function test_cari_without_keyword_sends_usage_instruction(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(123, '/cari'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Ketik /cari')
            && str_contains($r['text'], '/cari netflix'));
    }

    public function test_cari_with_matching_keyword_lists_active_available_products(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $matching = Product::factory()->create(['name' => 'Netflix Premium', 'description' => 'Akun streaming', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);
        Product::factory()->create(['name' => 'Spotify Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(124, '/cari netflix'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Hasil pencarian: netflix')
            && str_contains($r['text'], 'Netflix Premium')
            && ! str_contains($r['text'], 'Spotify Premium')
            && str_contains(json_encode($r->data()), 'product:'.$matching->id));
    }

    public function test_cari_result_can_be_selected_and_continues_existing_product_flow(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $product = Product::factory()->create(['name' => 'Canva Pro', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(125, '/cari canva'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(126, 'cb-search-product', 'product:'.$product->id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Canva Pro')
            && str_contains($r['text'], 'Pilih jumlah')
            && str_contains(json_encode($r->data()), 'qty:'.$product->id.':1'));
    }

    public function test_cari_without_matching_keyword_sends_fallback_to_catalog(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(127, '/cari zoom'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'tidak ditemukan')
            && str_contains($r['text'], '/katalog')
            && str_contains(json_encode($r->data()), 'katalog'));
    }

    public function test_cari_does_not_show_inactive_products(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => false, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(128, '/cari netflix'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'tidak ditemukan'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }

    public function test_cari_matches_case_insensitively(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(129, '/cari NETFLIX'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Netflix Premium')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }

    public function test_cari_does_not_show_products_from_inactive_categories(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $inactiveCategory = Category::factory()->create(['name' => 'Streaming', 'is_active' => false]);
        Product::factory()->create(['name' => 'Netflix Premium', 'category_id' => $inactiveCategory->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(130, '/cari netflix'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'tidak ditemukan'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }

    public function test_cari_does_not_show_fully_reserved_products(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        Product::factory()->create(['name' => 'Netflix Premium', 'is_active' => true, 'stock' => 5, 'reserved_stock' => 5]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(131, '/cari netflix'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains(json_encode($r->data()), 'tidak ditemukan'));
        Http::assertNotSent(fn ($r) => str_contains(json_encode($r->data()), 'product:'));
    }

    public function test_cari_can_match_active_category_name(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $category = Category::factory()->create(['name' => 'Streaming', 'is_active' => true]);
        $product = Product::factory()->create(['name' => 'Netflix Premium', 'category_id' => $category->id, 'is_active' => true, 'stock' => 5, 'reserved_stock' => 0]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(132, '/cari streaming'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Netflix Premium')
            && str_contains(json_encode($r->data()), 'product:'.$product->id));
    }

    public function test_product_callback_shows_stock_choices_and_quantity_button_adds_cart_item(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'is_active' => true]);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(13, 'cb1', 'product:'.$product->id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(14, 'cb-qty', 'qty:'.$product->id.':2'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->quantity);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains(json_encode($r->data()), 'qty:'.$product->id.':2'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains(json_encode($r->data()), 'Pembayaran'));
    }

    public function test_manual_non_numeric_quantity_keeps_selected_product_for_retry(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $product = Product::factory()->create(['name' => 'Kopi', 'stock' => 5, 'is_active' => true]);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(9001, 'cb-product', 'product:'.$product->id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(9002, 'abc'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(9003, '2'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertSame(1, CartItem::count());
        $this->assertSame(2, CartItem::first()->quantity);
    }

    public function test_invalid_product_callback_and_invalid_quantity_do_not_mutate_cart(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $inactive = Product::factory()->create(['is_active' => false]);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(15, 'cb2', 'product:'.$inactive->id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(16, 'cb-qty-bad', 'qty:'.$inactive->id.':1'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertSame(0, CartItem::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/answerCallbackQuery') && ($r['text'] ?? '') === 'Produk tidak tersedia.');
    }

    public function test_katalog_clears_selected_product_and_invalid_callback_answers_once(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $product = Product::factory()->create(['stock' => 5]);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(111, 'cb111', 'product:'.$product->id), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(112, '/katalog'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(113, '2'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(114, 'bad-cb', 'product:0'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertSame(0, CartItem::count());
        $answers = 0;
        Http::assertSent(function ($r) use (&$answers) {
            if (str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'bad-cb') {
                $answers++;
            }

            return true;
        });
        $this->assertSame(1, $answers);
    }

    public function test_batal_abandons_cart_and_status_reports_latest_order(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        app(CartService::class)->activeCartFor($customer);
        $order = Order::factory()->create(['customer_id' => $customer->id]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(115, '/batal'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(116, '/status'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertDatabaseHas('carts', ['customer_id' => $customer->id, 'status' => 'abandoned']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], $order->invoice_number));
    }

    public function test_riwayat_lists_latest_customer_orders_with_detail_buttons(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $old = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OLD', 'created_at' => now()->subDay()]);
        $new = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-NEW', 'payment_status' => PaymentStatus::Paid, 'created_at' => now()]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(201, '/riwayat'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Riwayat Pesanan')
            && strpos($r['text'], $new->invoice_number) < strpos($r['text'], $old->invoice_number)
            && str_contains($r['text'], 'paid')
            && str_contains(json_encode($r->data()), 'order:'.$new->invoice_number));
    }

    public function test_riwayat_fallback_when_no_orders(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(202, '/riwayat'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Belum ada riwayat')
            && str_contains($r['text'], '/katalog'));
    }

    public function test_riwayat_hides_other_customer_orders(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-OWN']);
        Order::factory()->create(['invoice_number' => 'INV-OTHER']);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(203, '/riwayat'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], $own->invoice_number)
            && ! str_contains($r['text'], 'INV-OTHER'));
    }

    public function test_order_without_invoice_sends_usage_instruction(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(204, '/order'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Ketik /order')
            && str_contains($r['text'], 'INV-'));
    }

    public function test_order_valid_detail_includes_items_payment_and_pending_instruction(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-DETAIL', 'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Canva Pro', 'quantity' => 2, 'subtotal' => 20000]);
        Payment::factory()->create(['order_id' => $order->id, 'method' => 'hosted', 'provider' => 'pakasir', 'status' => PaymentStatus::Pending]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(205, '/order INV-DETAIL'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Detail Pesanan')
            && str_contains($r['text'], 'Canva Pro x 2')
            && str_contains($r['text'], 'hosted')
            && str_contains($r['text'], 'Selesaikan pembayaran'));
    }

    public function test_order_cannot_access_other_customer_order(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        Order::factory()->create(['invoice_number' => 'INV-SECRET']);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(206, '/order INV-SECRET'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-SECRET'));
    }

    public function test_order_callback_valid_detail_and_manipulated_callback_cannot_leak_other_order(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $own = Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-CB', 'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid]);
        OrderItem::factory()->create(['order_id' => $own->id, 'product_name' => 'Netflix']);
        Order::factory()->create(['invoice_number' => 'INV-OTHER-CB']);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(207, 'cb-order', 'order:INV-CB'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(208, 'cb-order-bad', 'order:INV-OTHER-CB'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'INV-CB')
            && str_contains($r['text'], 'Pembayaran diterima'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains($r['text'], 'Pesanan tidak ditemukan')
            && ! str_contains($r['text'], 'INV-OTHER-CB'));
    }

    public function test_order_detail_expired_or_cancelled_instructs_checkout_again(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-EXP', 'order_status' => OrderStatus::Expired, 'payment_status' => PaymentStatus::Expired]);
        Order::factory()->create(['customer_id' => $customer->id, 'invoice_number' => 'INV-CAN', 'order_status' => OrderStatus::Cancelled, 'payment_status' => PaymentStatus::Failed]);

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(209, '/order INV-EXP'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(210, '/order INV-CAN'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'checkout ulang lewat /katalog'));
    }

    public function test_checkout_callback_creates_manual_order_sends_payment_instructions_and_notifies_admin(): void
    {
        config()->set('services.telegram.payment_mode', 'manual_dana');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        ManualPaymentSetting::create(['admin_contact_url' => 'https://t.me/adminsedulur', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(17, 'cb3', 'checkout'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $order = Order::firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'manual', 'method' => 'DANA_QR', 'provider_reference' => null]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '12345'
            && str_contains($r['text'], 'Halo admin, saya ingin konfirmasi pembelian')
            && str_contains($r['text'], 'GPT Plus'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Order manual baru masuk dashboard')
            && str_contains($r['text'], $order->invoice_number));
    }

    public function test_checkout_callback_in_pakasir_mode_sends_qr_with_direct_payment_button(): void
    {
        config()->set('services.telegram.payment_mode', 'pakasir');
        config()->set('services.pakasir', ['slug' => 'sedulur-digital', 'api_key' => 'pakasir-key', 'base_url' => 'https://app.pakasir.test', 'redirect_url' => null, 'qris_only' => false]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        ManualPaymentSetting::create(['admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'GPT Plus', 'price_amount' => 15000, 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 2);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(18, 'cb-pakasir', 'checkout'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $order = Order::firstOrFail();
        $payment = $order->payments()->firstOrFail();
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'provider' => 'pakasir', 'method' => 'hosted', 'amount' => 30000]);
        $this->assertNotEmpty($payment->merchant_ref);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto'));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && str_contains((string) ($r['text'] ?? ''), 'https://app.pakasir.test/pay/sedulur-digital/30000?order_id='.rawurlencode($payment->merchant_ref)));
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === '999'
            && str_contains($r['text'], 'Order manual baru masuk dashboard'));
    }

    public function test_payment_photo_is_forwarded_to_admin_and_admin_approval_sends_receipt(): void
    {
        config()->set('services.telegram.payment_mode', 'manual');
        config()->set('services.telegram.admin_user_id', '999');
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['name' => 'Email Account', 'stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);

        $this->postJson('/api/webhook/telegram', $this->callbackUpdate(211, 'cb-manual', 'checkout'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $order = Order::firstOrFail();

        $this->postJson('/api/webhook/telegram', [
            'update_id' => 212,
            'message' => [
                'message_id' => 212,
                'chat' => ['id' => 12345],
                'from' => ['id' => 12345, 'first_name' => 'Tester', 'username' => 'buyer'],
                'caption' => '/payment '.$order->invoice_number,
                'photo' => [['file_id' => 'small-photo', 'width' => 100, 'height' => 100], ['file_id' => 'large-photo', 'width' => 800, 'height' => 800]],
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendPhoto')
            && $r['chat_id'] === '999'
            && $r['photo'] === 'large-photo'
            && str_contains((string) ($r['caption'] ?? ''), 'Bukti pembayaran masuk'));

        $this->postJson('/api/webhook/telegram', [
            'update_id' => 213,
            'callback_query' => [
                'id' => 'approve-payment',
                'from' => ['id' => 999, 'first_name' => 'Admin'],
                'message' => ['chat' => ['id' => 999]],
                'data' => 'admin:approve-payment:'.$order->id,
            ],
        ], ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage')
            && $r['chat_id'] === 12345
            && str_contains($r['text'], 'Pembayaran diterima')
            && str_contains($r['text'], 'Email Account'));
    }

    public function test_receipt_notifier_sends_paid_order_receipt(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 777]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 777 && str_contains($r['text'], 'Pembayaran diterima'));
    }

    public function test_pakasir_paid_callback_sends_telegram_receipt_once_on_duplicate_callback(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 888]);
        $product = Product::factory()->create(['stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'hosted', 'pakasir');
        $payment = $order->payments()->first();
        $payload = ['project' => 'sedulur-digital', 'order_id' => $payment->merchant_ref, 'amount' => $payment->amount, 'status' => 'completed'];
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true]), 'https://app.pakasir.test/api/transactiondetail*' => Http::response(['transaction' => $payload])]);

        $this->postJson('/api/webhook/pakasir', $payload)->assertOk();
        $this->postJson('/api/webhook/pakasir', $payload)->assertUnprocessable();

        $sent = 0;
        Http::assertSent(function ($r) use (&$sent) {
            if (str_ends_with($r->url(), '/sendMessage') && ($r['chat_id'] ?? null) === 888) {
                $sent++;
            }

            return true;
        });
        $this->assertSame(1, $sent);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_telegram_webhook_is_rate_limited_by_user(): void
    {
        config()->set('services.telegram.webhook_rate_limit_per_minute', 1);
        Bus::fake();

        $this->postJson('/api/webhook/telegram', $this->messageUpdate(120, '/start'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertOk();
        $this->postJson('/api/webhook/telegram', $this->messageUpdate(121, '/start'), ['X-Telegram-Bot-Api-Secret-Token' => 'secret'])->assertTooManyRequests();
    }

    private function messageUpdate(int $id, string $text, array $from = []): array
    {
        return ['update_id' => $id, 'message' => ['message_id' => $id, 'chat' => ['id' => 12345], 'from' => array_merge(['id' => 12345, 'first_name' => 'Tester'], $from), 'text' => $text]];
    }

    private function callbackUpdate(int $id, string $callbackId, string $data): array
    {
        return ['update_id' => $id, 'callback_query' => ['id' => $callbackId, 'from' => ['id' => 12345, 'first_name' => 'Tester'], 'message' => ['chat' => ['id' => 12345]], 'data' => $data]];
    }

}
