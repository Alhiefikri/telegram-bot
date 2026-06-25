<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('Tanya stok');
    }
}
