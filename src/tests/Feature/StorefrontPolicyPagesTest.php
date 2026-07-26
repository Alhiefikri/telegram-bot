<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StorefrontPolicyPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_pages_are_public_and_contain_real_operational_rules(): void
    {
        $this->get(route('storefront.policies.privacy'))
            ->assertOk()
            ->assertViewIs('storefront.policy')
            ->assertSee('Kebijakan Privasi')
            ->assertSee('Recovery Key hanya ditampilkan satu kali')
            ->assertSee('Support tidak dapat membaca, mengirim ulang, atau menebak Recovery Key');

        $this->get(route('storefront.policies.terms'))
            ->assertOk()
            ->assertViewIs('storefront.policy')
            ->assertSee('Syarat Penggunaan')
            ->assertSee('Nomor invoice saja tidak cukup untuk membuka kredensial')
            ->assertSee('Batas kewenangan support');

        $this->get(route('storefront.policies.refund'))
            ->assertOk()
            ->assertViewIs('storefront.policy')
            ->assertSee('Kebijakan Refund')
            ->assertSee('Order belum dibayar')
            ->assertSee('Pembayaran gagal atau expired')
            ->assertSee('Produk belum dikirim')
            ->assertSee('Kredensial sudah dibuka');

        $this->get(route('storefront.policies.recovery'))
            ->assertOk()
            ->assertViewIs('storefront.policy')
            ->assertSee('Pemulihan Pesanan')
            ->assertSee('Order website yang kehilangan Recovery Key tidak dapat dipulihkan secara manual')
            ->assertSee('audit trail internal')
            ->assertSee('Kredensial tidak boleh dibuka berdasarkan invoice');
    }

    public function test_storefront_footer_links_to_real_policy_destinations(): void
    {
        foreach ([route('storefront.home'), route('storefront.products.show', ['product' => Product::factory()->create()])] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('href="'.route('storefront.policies.privacy').'"', false)
                ->assertSee('href="'.route('storefront.policies.terms').'"', false)
                ->assertSee('href="'.route('storefront.policies.refund').'"', false)
                ->assertSee('href="'.route('storefront.policies.recovery').'"', false)
                ->assertDontSee('href="'.route('storefront.home').'#faq" class="hover:text-[var(--sf-accent)]">Kebijakan Privasi', false)
                ->assertDontSee('href="'.route('storefront.home').'#faq" class="hover:text-[var(--sf-accent)]">Syarat Ketentuan', false);
        }

        $this->get(route('storefront.shop.index'))
            ->assertRedirect(url('/#products'));
    }

    public function test_recovery_surfaces_use_the_approved_support_boundary(): void
    {
        $this->get(route('storefront.order-access.create'))
            ->assertOk()
            ->assertSee('support tidak dapat mengirim ulang key atau membuka kredensial hanya dari nomor invoice')
            ->assertSee('data akun tidak bisa dibuka otomatis atau dipulihkan manual oleh support')
            ->assertSee('href="'.route('storefront.policies.recovery').'"', false);
    }
}
