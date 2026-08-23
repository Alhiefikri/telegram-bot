<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\TelegramBotTestSupport;

class SmokeE2ETest extends TelegramBotTestSupport
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.credentials.identity_fingerprint_key', 'smoke-test-fingerprint-key');
        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
    }

    public function test_admin_add_manual_stock_and_buyer_purchase(): void
    {
        // 1. ADMIN SCENARIO: Create Product and Add Stock
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Akun Smoke Test',
            'price_amount' => 150000,
            'stock' => 0,
            'delivery_note' => "1. Buka aplikasi\n2. Masukkan email dan password\n3. Gunakan Access Token",
        ]);

        $this->actingAs($admin)
            ->postJson(route('admin.products.stock.accounts.store', $product), [
                'operation_key' => Str::uuid()->toString(),
                'accounts' => [
                    [
                        'email' => 'smoketest@example.com',
                        'password' => 'Rahasia123',
                        'access_method' => 'auth_token',
                        'access_value' => 'TOKEN_123_ABC',
                    ],
                ],
            ])
            ->assertStatus(201)
            ->assertJsonPath('item_count', 1);

        $this->assertEquals(1, $product->fresh()->stock);

        echo "\n=== ADMIN SCENARIO ===\n";
        echo 'Admin added 1 manual stock for product: '.$product->name."\n";
        echo "- Email: smoketest@example.com\n- Pass: Rahasia123\n- Method: auth_token\n- Value: TOKEN_123_ABC\n";

        // 2. BUYER SCENARIO: Purchase the product
        $customer = Customer::factory()->create(['telegram_id' => 123456789, 'locale' => 'id']);

        $order = Order::factory()->for($customer)->create([
            'total_amount' => 150000,
            'currency' => 'IDR',
            'invoice_number' => 'INV-SMOKE',
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 150000,
            'subtotal' => 150000,
        ]);

        // Pay the order
        $order->payments()->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'method' => 'manual',
            'provider' => 'manual', 'merchant_ref' => 'MR-SMOKE-1',
            'amount' => 150000,
            'currency' => 'IDR',
        ]);
        $order->update(['payment_status' => PaymentStatus::Paid, 'order_status' => OrderStatus::Completed]);

        // Reserve stock
        $stockItem = $product->stockItems()->first();
        $stockItem->update(['order_item_id' => $order->orderItems->first()->id]);
        $product->update(['stock' => 0]);

        // Dispatch Notifier
        $this->fakeTelegramHttp();
        app(TelegramReceiptNotifier::class)->send($order);

        $messages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $messages);

        $caption = (string) $this->telegramRequestField($messages->first()[0], 'caption');

        echo "\n=== BUYER SCENARIO: RECEIPT RECEIVED ===\n";
        echo $caption;
        echo "\n=======================================\n";
    }
}
