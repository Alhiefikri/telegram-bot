<?php

namespace Tests\Feature;

use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendTelegramReceipt;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_path_returns_public_storefront_without_admin_redirect(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Toko Produk Digital Instan')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('property="og:title"', false)
            ->assertSee('/admin/login')
            ->assertDontSee("Let's get started");
    }

    public function test_root_path_renders_trust_payment_telegram_and_faq_content_without_dispatching_webhook_jobs(): void
    {
        Bus::fake([ProcessTelegramUpdate::class]);
        Queue::fake([SendTelegramReceipt::class]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Alur pembayaran')
            ->assertSee('Setelah pembayaran terkonfirmasi')
            ->assertSee('Pengiriman digital')
            ->assertSee('Bot dan admin Telegram membantu status order')
            ->assertSee('Pembayaran aman')
            ->assertSee('Pengiriman cepat')
            ->assertSee('Stok transparan')
            ->assertSee('Metode pembayaran apa yang tersedia?')
            ->assertSee('Apakah bot Telegram memproses order dari halaman ini?');

        Bus::assertNotDispatched(ProcessTelegramUpdate::class);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_empty_catalog_renders_customer_facing_state(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Katalog publik sedang disiapkan')
            ->assertSee('Kategori akan segera hadir')
            ->assertSee('pelanggan bisa menghubungi support');
    }

    public function test_active_products_render_while_inactive_and_deleted_products_stay_hidden(): void
    {
        $category = Category::factory()->create([
            'name' => 'AI Tools',
            'slug' => 'ai-tools',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Canva Pro Bulanan',
            'description' => 'Akses desain premium untuk kebutuhan konten harian.',
            'price_amount' => 125000,
            'stock' => 7,
            'reserved_stock' => 2,
            'image_path' => null,
            'is_active' => true,
        ]);
        $inactiveCategory = Category::factory()->create(['name' => 'Kategori Produk Nonaktif', 'slug' => 'kategori-produk-nonaktif', 'is_active' => false]);
        Product::factory()->create(['category_id' => $inactiveCategory->id, 'name' => 'Produk Dari Kategori Nonaktif']);
        $deletedCategory = Category::factory()->create(['name' => 'Kategori Produk Terhapus', 'slug' => 'kategori-produk-terhapus']);
        Product::factory()->create(['category_id' => $deletedCategory->id, 'name' => 'Produk Dari Kategori Terhapus']);
        $deletedCategory->delete();
        Product::factory()->create(['name' => 'Produk Nonaktif', 'is_active' => false]);
        $deleted = Product::factory()->create(['name' => 'Produk Terhapus']);
        $deleted->delete();

        $this->get('/')
            ->assertOk()
            ->assertSee('Canva Pro Bulanan')
            ->assertSee('AI Tools')
            ->assertSee('IDR 125.000')
            ->assertSee('Stok 5')
            ->assertSee('Gambar produk menyusul')
            ->assertDontSee('Produk Nonaktif')
            ->assertDontSee('Produk Terhapus')
            ->assertDontSee('Produk Dari Kategori Nonaktif')
            ->assertDontSee('Produk Dari Kategori Terhapus');
    }

    public function test_active_categories_render_in_sort_order_while_inactive_and_deleted_stay_hidden(): void
    {
        Category::factory()->create(['name' => 'AI Tools', 'slug' => 'ai-tools', 'sort_order' => 20, 'is_active' => true]);
        Category::factory()->create(['name' => 'Akun Premium', 'slug' => 'akun-premium', 'sort_order' => 10, 'is_active' => true]);
        Category::factory()->create(['name' => 'Kategori Nonaktif', 'slug' => 'kategori-nonaktif', 'is_active' => false]);
        $deleted = Category::factory()->create(['name' => 'Kategori Terhapus', 'slug' => 'kategori-terhapus']);
        $deleted->delete();

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Akun Premium', 'AI Tools'])
            ->assertDontSee('Kategori Nonaktif')
            ->assertDontSee('Kategori Terhapus');
    }

    public function test_featured_offer_selects_available_public_product_only(): void
    {
        $category = Category::factory()->create([
            'name' => 'Akun Premium',
            'slug' => 'akun-premium',
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Akun Habis',
            'description' => 'Produk aktif tetapi stoknya tidak tersedia.',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Produk Nonaktif',
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => false,
        ]);
        $deleted = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Produk Terhapus',
            'stock' => 5,
            'reserved_stock' => 0,
        ]);
        $deleted->delete();
        Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Canva Pro Promo',
            'description' => 'Offer utama dengan stok siap kirim.',
            'price_amount' => 99000,
            'stock' => 4,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $featuredHtml = $this->htmlBetween($html, '<section id="featured-offer"', '<section id="products"');

        $this->assertStringContainsString('Promo pilihan hari ini', $featuredHtml);
        $this->assertStringContainsString('Canva Pro Promo', $featuredHtml);
        $this->assertStringContainsString('IDR 99.000', $featuredHtml);
        $this->assertStringContainsString('Stok siap 3', $featuredHtml);
        $this->assertStringContainsString('Chat support untuk beli', $featuredHtml);
        $this->assertStringNotContainsString('Akun Habis', $featuredHtml);
        $this->assertStringNotContainsString('Produk Nonaktif', $featuredHtml);
        $this->assertStringNotContainsString('Produk Terhapus', $featuredHtml);
    }

    public function test_featured_offer_has_safe_empty_state_without_suitable_product(): void
    {
        Product::factory()->create([
            'name' => 'Akun Habis',
            'stock' => 2,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Produk Nonaktif Siap',
            'stock' => 5,
            'reserved_stock' => 0,
            'is_active' => false,
        ]);

        $html = $this->get('/')
            ->assertOk()
            ->getContent();

        $featuredHtml = $this->htmlBetween($html, '<section id="featured-offer"', '<section id="products"');

        $this->assertStringContainsString('Promo pilihan menunggu stok siap', $featuredHtml);
        $this->assertStringContainsString('Minta rekomendasi ke support', $featuredHtml);
        $this->assertStringNotContainsString('Chat support untuk beli', $featuredHtml);
        $this->assertStringNotContainsString('Akun Habis', $featuredHtml);
        $this->assertStringNotContainsString('Produk Nonaktif Siap', $featuredHtml);
    }

    public function test_category_controls_link_to_ordered_catalog_sections(): void
    {
        $later = Category::factory()->create(['name' => 'AI Tools', 'slug' => 'ai-tools', 'sort_order' => 20, 'is_active' => true]);
        $first = Category::factory()->create(['name' => 'Akun Premium', 'slug' => 'akun-premium', 'sort_order' => 10, 'is_active' => true]);
        Product::factory()->create(['category_id' => $later->id, 'name' => 'Produk AI', 'is_active' => true]);
        Product::factory()->create(['category_id' => $first->id, 'name' => 'Produk Premium', 'is_active' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['href="#category-akun-premium"', 'href="#category-ai-tools"'], false)
            ->assertSee('id="category-akun-premium"', false)
            ->assertSee('id="category-ai-tools"', false);
    }

    public function test_out_of_stock_products_are_deprioritized_and_not_presented_as_ready_to_buy(): void
    {
        Product::factory()->create([
            'name' => 'Akun Kosong',
            'description' => 'Produk tanpa stok siap kirim.',
            'stock' => 1,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);
        Product::factory()->create([
            'name' => 'Zeta Ready',
            'description' => 'Produk dengan stok siap kirim.',
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSeeInOrder(['Zeta Ready', 'Akun Kosong'])
            ->assertSee('Stok habis')
            ->assertSee('Tanya stok ke support')
            ->assertSee('Chat support untuk beli')
            ->assertSee('checkout/detail publik belum dibuka')
            ->assertSee('href="#support"', false);
    }

    private function htmlBetween(string $html, string $start, string $end): string
    {
        $startPosition = strpos($html, $start);
        $this->assertNotFalse($startPosition, "Missing start marker [{$start}].");

        $endPosition = strpos($html, $end, $startPosition);
        $this->assertNotFalse($endPosition, "Missing end marker [{$end}].");

        return substr($html, $startPosition, $endPosition - $startPosition);
    }
}
