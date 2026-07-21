<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_storefront_page_uses_the_branded_not_found_page(): void
    {
        $this->get('/halaman-yang-tidak-ada')
            ->assertNotFound()
            ->assertSee('Halaman ini tidak ditemukan.')
            ->assertSee('Lihat katalog');
    }

    public function test_checkout_rate_limit_uses_the_recovery_page_and_preserves_retry_headers(): void
    {
        config()->set('services.storefront.checkout_rate_limit_per_minute', 1);

        $payload = [
            'product_id' => 999_999,
            'quantity' => 1,
            'buyer_name' => 'buyer_test',
        ];

        $this->post(route('storefront.shop.checkout'), $payload)->assertRedirect();

        $this->post(route('storefront.shop.checkout'), $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Tunggu sebentar sebelum checkout lagi.')
            ->assertSee('Pesanan Anda belum dibuat dan stok belum berkurang.');
    }

    public function test_order_access_rate_limit_uses_recovery_specific_copy(): void
    {
        config()->set('services.storefront.credential_access_rate_limit_per_minute', 1);

        $payload = [
            'invoice_number' => 'INV-00000001',
            'access_key' => 'LHIE-RECOVERYKEY',
        ];

        $this->post(route('storefront.order-access.store'), $payload)->assertRedirect();

        $this->post(route('storefront.order-access.store'), $payload)
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertSee('Tunggu sebentar sebelum mencoba recovery key lagi.')
            ->assertSee('Pastikan nomor invoice dan recovery key yang disimpan sudah benar sebelum mencoba lagi.');
    }
}
