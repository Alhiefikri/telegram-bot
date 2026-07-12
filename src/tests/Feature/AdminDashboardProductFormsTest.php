<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\TestCase;

class AdminDashboardProductFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_modal_renders_compact_create_form(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        Category::factory()->create(['name' => 'Akun Premium']);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Tambah produk')
            ->assertSee('Nama produk')
            ->assertSee('Harga')
            ->assertSee('Kategori')
            ->assertSee('Deskripsi singkat')
            ->assertSee('Catatan pengiriman rahasia')
            ->assertDontSee('Fitur utama')
            ->assertDontSee('Paket / spesifikasi')
            ->assertSee('Akun Premium')
            ->assertSee('Submit produk')
            ->assertSee('x-data', false)
            ->assertSee('min-w-0', false)
            ->assertDontSee('fi-form', false);
    }

    public function test_product_image_upload_control_uses_custom_responsive_file_picker(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Pilih gambar')
            ->assertSee('productImageFileName', false)
            ->assertSee('sr-only', false)
            ->assertDontSee('file:mr-3', false);
    }

    public function test_admin_can_create_product_from_dashboard_modal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Tools Premium']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Canva Pro 1 Bulan',
                'price_amount' => '75000',
                'category_id' => (string) $category->id,
                'description' => 'Akses Canva Pro siap pakai.',
                'delivery_note' => "Login menggunakan data akun yang dikirim.\nGanti password setelah berhasil masuk.",
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHas('status', 'Produk Canva Pro 1 Bulan berhasil dibuat. Tambahkan stok saat item sudah siap.');

        $this->assertDatabaseHas('products', [
            'category_id' => $category->id,
            'name' => 'Canva Pro 1 Bulan',
            'price_amount' => 75000,
            'currency' => 'IDR',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $product = Product::where('name', 'Canva Pro 1 Bulan')->firstOrFail();
        $this->assertSame('Akses Canva Pro siap pakai.', $product->description);
        $this->assertSame("Login menggunakan data akun yang dikirim.\nGanti password setelah berhasil masuk.", $product->delivery_note);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'product.created',
            'subject_id' => (string) $product->id,
        ]);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Canva Pro 1 Bulan')
            ->assertSee('Tools Premium')
            ->assertSee('IDR 75.000')
            ->assertSee('Aktif')
            ->assertSeeInOrder(['Stok', '0'])
            ->assertSeeInOrder(['Tersedia', '0'])
            ->assertSeeInOrder(['Dipesan', '0'])
            ->assertSee('Akses Canva Pro siap pakai.');
    }

    public function test_admin_can_upload_product_image_as_optimized_webp(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Tools Premium']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Figma Pro 1 Bulan',
                'price_amount' => '85000',
                'category_id' => (string) $category->id,
                'description' => 'Akses desain premium.',
                'image' => UploadedFile::fake()->image('cover.png', 2400, 1000),
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $product = Product::where('name', 'Figma Pro 1 Bulan')->firstOrFail();

        $this->assertStringStartsWith('products/', $product->image_path);
        $this->assertStringEndsWith('.webp', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);

        [$width, $height, $type] = getimagesize(Storage::disk('public')->path($product->image_path));
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(1600, $width);
        $this->assertSame(667, $height);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('/storage/'.$product->image_path, false);
    }

    public function test_admin_can_replace_product_image_without_clobbering_other_fields(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/old.webp', 'old-webp');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Old Image Product',
            'description' => 'Original description',
            'price_amount' => 25000,
            'stock' => 7,
            'reserved_stock' => 2,
            'image_path' => 'products/old.webp',
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'New Image Product',
                'price_amount' => '99000',
                'description' => 'Updated description.',
                'is_active' => '0',
                'image' => UploadedFile::fake()->image('replacement.jpg', 900, 1200),
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $product->refresh();

        $this->assertSame('New Image Product', $product->name);
        $this->assertSame(99000, $product->price_amount);
        $this->assertFalse($product->is_active);
        $this->assertSame(7, $product->stock);
        $this->assertSame(2, $product->reserved_stock);
        $this->assertStringStartsWith('products/', $product->image_path);
        $this->assertStringEndsWith('.webp', $product->image_path);
        $this->assertNotSame('products/old.webp', $product->image_path);
        Storage::disk('public')->assertMissing('products/old.webp');
        Storage::disk('public')->assertExists($product->image_path);

        [$width, $height, $type] = getimagesize(Storage::disk('public')->path($product->image_path));
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(900, $width);
        $this->assertSame(1200, $height);
    }

    public function test_failed_product_image_upload_returns_actionable_validation_message(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $path = tempnam(sys_get_temp_dir(), 'failed-product-image-');
        file_put_contents($path, 'not uploaded');

        try {
            $this->actingAs($admin)
                ->from('/admin?tab=produk')
                ->post(route('admin.products.store'), [
                    'name' => 'Upload Error Product',
                    'price_amount' => '35000',
                    'description' => 'Produk dengan upload gagal.',
                    'image' => new SymfonyUploadedFile($path, 'large.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true),
                ])
                ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
                ->assertSessionHasErrors([
                    'image' => 'Upload gambar gagal. Maksimal 5 MB; coba kompres atau pilih gambar lain.',
                ], null, 'productCreate');
        } finally {
            @unlink($path);
        }
    }

    public function test_owner_can_create_product_with_optional_sections_empty(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Kosong Fitur',
                'price_amount' => '15000',
                'category_id' => '',
                'description' => 'Produk tanpa fitur tambahan.',
                'delivery_note' => '',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $product = Product::where('name', 'Kosong Fitur')->firstOrFail();

        $this->assertSame('Produk tanpa fitur tambahan.', $product->description);
        $this->assertNull($product->category_id);
        $this->assertSame(0, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertTrue($product->is_active);
    }

    public function test_admin_can_update_and_delete_product_from_dashboard_modal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Streaming']);
        $product = Product::factory()->create([
            'name' => 'Old Name',
            'category_id' => null,
            'description' => 'Old description',
            'price_amount' => 25000,
            'stock' => 7,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'Netflix Premium',
                'price_amount' => '99000',
                'category_id' => (string) $category->id,
                'description' => 'Akses streaming siap pakai.',
                'delivery_note' => 'Login dari perangkat pribadi lalu ganti password.',
                'is_active' => '0',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $product->refresh();
        $this->assertSame('Netflix Premium', $product->name);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame(99000, $product->price_amount);
        $this->assertFalse($product->is_active);
        $this->assertSame(7, $product->stock);
        $this->assertSame(2, $product->reserved_stock);
        $this->assertSame('Akses streaming siap pakai.', $product->description);
        $this->assertSame('Login dari perangkat pribadi lalu ganti password.', $product->delivery_note);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'product.updated',
            'subject_id' => (string) $product->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_product_modal_submission_validates_required_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => '',
                'price_amount' => '',
                'description' => '',
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['name', 'price_amount', 'description'], null, 'productCreate');

        $this->assertDatabaseCount('products', 0);

        $this->followingRedirects()
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => '',
                'price_amount' => '',
                'description' => '',
            ])
            ->assertOk()
            ->assertSee('\u0022productModalOpen\u0022:true', false)
            ->assertSee('\u0022productModalMode\u0022:\u0022create\u0022', false)
            ->assertSee('Periksa kembali field produk yang ditandai.');
    }

    public function test_invalid_product_update_reopens_edit_modal_with_submitted_state(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create([
            'name' => 'Original Product',
            'description' => 'Original description',
            'price_amount' => 25000,
            'is_active' => true,
        ]);

        $this->followingRedirects()
            ->from('/admin?tab=stats')
            ->patch(route('admin.products.update', $product), [
                'name' => '',
                'price_amount' => '99000',
                'description' => 'Submitted description',
                'delivery_note' => "Submitted delivery note\nSecond delivery note",
                'is_active' => '0',
            ])
            ->assertOk()
            ->assertSee('\u0022activeTab\u0022:\u0022produk\u0022', false)
            ->assertSee('\u0022productModalOpen\u0022:true', false)
            ->assertSee('\u0022productModalMode\u0022:\u0022edit\u0022', false)
            ->assertSee('\u0022productFormMethod\u0022:\u0022PATCH\u0022', false)
            ->assertSee('\u0022productId\u0022:'.$product->id, false)
            ->assertSee('\u0022productPrice\u0022:\u002299000\u0022', false)
            ->assertSee('Submitted description')
            ->assertSee('Submitted delivery note')
            ->assertSee('Second delivery note')
            ->assertSee('Periksa kembali field produk yang ditandai.');

        $product->refresh();
        $this->assertSame('Original Product', $product->name);
        $this->assertSame(25000, $product->price_amount);
        $this->assertTrue($product->is_active);
    }

    public function test_admin_can_pause_and_play_product_without_clobbering_product_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Streaming']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Netflix Premium',
            'description' => 'Original description',
            'price_amount' => 99000,
            'stock' => 3,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.products.visibility.update', $product), ['is_active' => '0'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHas('status', 'Produk Netflix Premium dipause.');

        $product->refresh();
        $this->assertFalse($product->is_active);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame('Netflix Premium', $product->name);
        $this->assertSame('Original description', $product->description);
        $this->assertSame(99000, $product->price_amount);
        $this->assertSame(3, $product->stock);
        $this->assertSame(1, $product->reserved_stock);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Pause')
            ->assertSee('○ Tidak tampil');

        $this->actingAs($admin)
            ->patch(route('admin.products.visibility.update', $product), ['is_active' => '1'])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHas('status', 'Produk Netflix Premium ditampilkan.');

        $this->assertTrue($product->fresh()->is_active);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Aktif')
            ->assertSee('● Tampil di toko');
    }

    public function test_admin_can_create_category_with_unique_slug_from_produk_tab(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Category::factory()->create(['name' => 'Akun Premium', 'slug' => 'akun-premium'])->delete();
        Category::factory()->create(['name' => 'Akun Premium Active', 'slug' => 'akun-premium-2']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.categories.store'), [
                'category_name' => 'Akun Premium',
                'category_description' => 'Akun siap jual',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHas('status', 'Kategori Akun Premium berhasil dibuat.');

        $this->assertDatabaseHas('categories', [
            'name' => 'Akun Premium',
            'slug' => 'akun-premium-3',
            'description' => 'Akun siap jual',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'category.created',
        ]);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Akun Premium');

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'category_name' => '!!!',
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']));

        $this->assertDatabaseHas('categories', ['slug' => 'kategori']);
    }

    public function test_category_modal_validation_uses_category_error_bag(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=produk')
            ->post(route('admin.categories.store'), ['category_name' => ''])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['category_name'], null, 'categoryCreate');

        $this->followingRedirects()
            ->from('/admin?tab=produk')
            ->post(route('admin.categories.store'), ['category_name' => str_repeat('A', 121)])
            ->assertOk()
            ->assertSee('Nama kategori maksimal 120 karakter.')
            ->assertSee('\u0022productModalOpen\u0022:false', false)
            ->assertDontSee('Periksa kembali field produk yang ditandai.');
    }

    public function test_product_update_validation_reopens_edit_modal_without_persisting_invalid_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Persisted Product',
            'description' => 'Persisted description',
            'price_amount' => 50000,
            'stock' => 4,
            'reserved_stock' => 1,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => '',
                'price_amount' => 'broken',
                'description' => '',
                'product_id' => (string) $product->id,
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHasErrors(['name', 'price_amount', 'description'], null, 'productUpdate');

        $product->refresh();
        $this->assertSame('Persisted Product', $product->name);
        $this->assertSame('Persisted description', $product->description);
        $this->assertSame(50000, $product->price_amount);
        $this->assertSame(4, $product->stock);
        $this->assertSame(1, $product->reserved_stock);

        $this->followingRedirects()
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => '',
                'price_amount' => 'broken',
                'description' => '',
                'product_id' => (string) $product->id,
            ])
            ->assertOk()
            ->assertSee('\u0022productModalOpen\u0022:true', false)
            ->assertSee('\u0022productModalMode\u0022:\u0022edit\u0022', false)
            ->assertSee('Periksa kembali field produk yang ditandai.');
    }

    public function test_owner_can_delete_product_without_exposing_stock_secrets_in_dashboard_response(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Owner]);
        $product = Product::factory()->create(['name' => 'Delete Me Product', 'stock' => 1, 'reserved_stock' => 0]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-product@example.test|pass']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHas('status', 'Produk Delete Me Product diarsipkan dari dashboard.');

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $dashboard = $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertDontSee(route('admin.products.update', $product), false);

        $this->assertStringNotContainsString('delete-product@example.test|pass', $dashboard->getContent());
    }
}
