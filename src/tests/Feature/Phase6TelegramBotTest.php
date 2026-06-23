<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
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
        config()->set('services.tripay', ['api_key' => 'k', 'private_key' => 'p', 'merchant_code' => 'M', 'base_url' => 'https://tripay.test', 'callback_url' => null, 'return_url' => null]);
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
        Http::assertSentCount(1);
    }

    public function test_duplicate_direct_job_does_not_send_twice(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $update = TelegramUpdate::create(['update_id' => 103, 'payload' => $this->messageUpdate(103, '/start'), 'created_at' => now()]);

        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        Http::assertSentCount(1);
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

    public function test_checkout_callback_creates_manual_order_sends_payment_instructions_and_notifies_admin(): void
    {
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

    public function test_receipt_notifier_sends_paid_order_receipt(): void
    {
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 777]);
        $order = Order::factory()->create(['customer_id' => $customer->id, 'payment_status' => PaymentStatus::Paid]);
        Payment::factory()->create(['order_id' => $order->id, 'status' => PaymentStatus::Paid]);

        app(TelegramReceiptNotifier::class)->send($order);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === 777 && str_contains($r['text'], 'Pembayaran diterima'));
    }

    public function test_tripay_paid_callback_sends_telegram_receipt_once_on_duplicate_callback(): void
    {
        Http::fake(['https://tripay.test/transaction/create' => Http::response(['success' => true, 'data' => ['reference' => 'TREF-CB']]), 'https://api.telegram.org/*' => Http::response(['ok' => true])]);
        $customer = Customer::factory()->create(['telegram_id' => 888]);
        $product = Product::factory()->create(['stock' => 5]);
        app(CartService::class)->addItem(app(CartService::class)->activeCartFor($customer), $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($customer->carts()->first(), 'BRIVA', 'tripay');
        $payment = $order->payments()->first();
        $payload = ['merchant_ref' => $payment->merchant_ref, 'reference' => 'TREF-CB', 'total_amount' => $payment->amount, 'status' => 'PAID', 'payment_method_code' => 'BRIVA'];

        $this->postTripayCallback($payload)->assertOk();
        $this->postTripayCallback($payload)->assertOk();

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

    private function postTripayCallback(array $payload)
    {
        $raw = json_encode($payload);

        return $this->call('POST', '/api/webhook/tripay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_CALLBACK_SIGNATURE' => hash_hmac('sha256', $raw, 'p'),
            'HTTP_X_CALLBACK_EVENT' => 'payment_status',
        ], $raw);
    }
}
