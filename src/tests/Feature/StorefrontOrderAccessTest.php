<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontOrderAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_access_page_explains_that_recovery_keys_are_only_for_website_orders(): void
    {
        $this->get(route('storefront.order-access.create'))
            ->assertOk()
            ->assertSee('Halaman ini hanya untuk pesanan yang dibeli melalui website.')
            ->assertSee('pesanan via Telegram tidak menggunakan Recovery Key.');
    }

    public function test_order_access_validation_errors_are_described_inline(): void
    {
        $this->post(route('storefront.order-access.store'), [
            'invoice_number' => '',
            'access_key' => '',
        ]);

        $this->get(route('storefront.order-access.create'))
            ->assertOk()
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="invoice-number-error"', false)
            ->assertSee('aria-describedby="access-key-help access-key-error"', false)
            ->assertSee('id="access-key-help"', false);
    }

    public function test_paid_credentials_require_the_recovery_key_and_can_be_opened_again_with_it(): void
    {
        [$order, $key, $secret] = $this->paidOrderWithAccessKey();
        $invoiceUrl = app(StorefrontInvoiceUrl::class)->show($order);

        $this->get($invoiceUrl)
            ->assertOk()
            ->assertDontSee($secret, false)
            ->assertSee('Akses data pesanan')
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->post(route('storefront.order-access.store'), [
            'invoice_number' => $order->invoice_number,
            'access_key' => $key,
        ])->assertRedirect(route('storefront.order-access.show', $order));

        $this->get(route('storefront.order-access.show', $order))
            ->assertOk()
            ->assertSee($secret, false)
            ->assertSee('Simpan data ini di password manager pribadi. Jangan kirim Recovery Key, password, MFA Secret, atau kode MFA ke support.')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_invalid_recovery_key_never_reveals_the_paid_credentials(): void
    {
        [$order, , $secret] = $this->paidOrderWithAccessKey();

        $this->post(route('storefront.order-access.store'), [
            'invoice_number' => $order->invoice_number,
            'access_key' => 'LHIE-THISISNOTTHERECOVERYKEY',
        ])
            ->assertRedirect(route('storefront.order-access.create'))
            ->assertSessionHas('storefront_order_access_error', 'Nomor invoice atau recovery key tidak cocok.');

        $this->get(route('storefront.order-access.show', $order))
            ->assertForbidden()
            ->assertSee('Data pesanan belum dapat dibuka.')
            ->assertSee('Masukkan invoice &amp; recovery key', false)
            ->assertDontSee($secret, false);
    }

    public function test_access_key_is_only_stored_as_a_hash_on_the_order(): void
    {
        [$order, $key] = $this->paidOrderWithAccessKey();

        $this->assertNotSame($key, $order->credential_access_key_hash);
        $this->assertStringNotContainsString($key, (string) $order->credential_access_key_hash);
    }

    /**
     * @return array{Order, string, string}
     */
    private function paidOrderWithAccessKey(): array
    {
        $order = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        Payment::factory()->create([
            'order_id' => $order->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $product = Product::factory()->create();
        $item = OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => 'Akun Aman',
        ]);
        $secret = 'customer@example.test|PasswordAman123';
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => $secret,
        ]);

        $key = app(StorefrontOrderAccess::class)->issue($order);

        return [$order->fresh(), $key, $secret];
    }
}
