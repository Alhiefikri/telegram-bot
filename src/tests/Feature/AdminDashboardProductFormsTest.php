<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductStockItem;
use App\Models\StorefrontSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Tests\TestCase;

class AdminDashboardProductFormsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_change_default_catalog_image_from_dashboard(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Uses Dashboard Placeholder',
            'image_path' => null,
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.storefront-placeholder.update'), [
                'image' => UploadedFile::fake()->image('placeholder-one.png', 900, 1200),
            ])
            ->assertRedirect('/admin?tab=produk#admin-section-products')
            ->assertSessionHas('status', 'Gambar default katalog berhasil diperbarui.');

        $setting = StorefrontSetting::query()->firstOrFail();
        $firstPath = $setting->product_placeholder_image_path;

        $this->assertStringStartsWith('storefront/placeholders/', $firstPath);
        $this->assertStringEndsWith('.webp', $firstPath);
        Storage::disk('public')->assertExists($firstPath);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'storefront.product_placeholder.updated',
            'subject_type' => StorefrontSetting::class,
            'subject_id' => (string) $setting->id,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('/storage/'.$firstPath, false)
            ->assertSee('Uses Dashboard Placeholder');
        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('/storage/'.$firstPath, false)
            ->assertSee('Default custom aktif');

        $this->actingAs($admin)
            ->post(route('admin.storefront-placeholder.update'), [
                'image' => UploadedFile::fake()->image('placeholder-two.png', 600, 600),
            ])
            ->assertRedirect('/admin?tab=produk#admin-section-products');

        $secondPath = $setting->refresh()->product_placeholder_image_path;

        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_non_manager_cannot_change_default_catalog_image(): void
    {
        Storage::fake('public');
        $support = User::factory()->create(['role' => UserRole::Support]);

        $this->actingAs($support)
            ->post(route('admin.storefront-placeholder.update'), [
                'image' => UploadedFile::fake()->image('not-allowed.png'),
            ])
            ->assertForbidden();

        $this->assertNull(StorefrontSetting::query()->firstOrFail()->product_placeholder_image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_admin_can_create_product_from_dashboard_modal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Tools Premium']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Canva Pro 1 Bulan',
                'icon' => '🖌️',
                'price_amount' => '75000',
                'category_id' => (string) $category->id,
                'description' => 'Akses Canva Pro siap pakai.',
                'warranty_policy' => 'Penggantian satu kali dalam 7 hari; refund setelah pemeriksaan admin.',
                'delivery_note' => "Login menggunakan data akun yang dikirim.\nGanti password setelah berhasil masuk.",
                'usage_guide_title' => 'Panduan penggunaan Canva Pro',
                'usage_guide_url' => 'https://docs.example.test/canva/getting-started',
            ])
            ->assertRedirect('/admin?tab=produk#product-1')
            ->assertSessionHas('status', 'Produk Canva Pro 1 Bulan berhasil dibuat. Tambahkan stok saat item sudah siap.');

        $this->assertDatabaseHas('products', [
            'category_id' => $category->id,
            'name' => 'Canva Pro 1 Bulan',
            'icon' => '🖌️',
            'price_amount' => 75000,
            'currency' => 'IDR',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $product = Product::where('name', 'Canva Pro 1 Bulan')->firstOrFail();
        $this->assertSame('Akses Canva Pro siap pakai.', $product->description);
        $this->assertSame('🖌️', $product->icon);
        $this->assertSame('Penggantian satu kali dalam 7 hari; refund setelah pemeriksaan admin.', $product->warranty_policy);
        $this->assertSame("Login menggunakan data akun yang dikirim.\nGanti password setelah berhasil masuk.", $product->delivery_note);
        $this->assertSame('Panduan penggunaan Canva Pro', $product->usage_guide_title);
        $this->assertSame('https://docs.example.test/canva/getting-started', $product->usage_guide_url);

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

            ->assertSee('Akses Canva Pro siap pakai.')
            ->assertSee('Replacement &amp; Refund Policy', false)
            ->assertSee('productWarrantyPolicy', false)

            ->assertSee('/assets/product-placeholder.svg', false);
    }

    public function test_admin_can_create_replace_and_disable_quantity_price_tiers(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Tiered Product',
                'price_amount' => '50000',
                'usdt_price_amount' => '2',
                'description' => 'Produk dengan harga bertingkat.',
                'quantity_price_tiers_present' => '1',
                'quantity_price_tiers' => [
                    ['minimum_quantity' => '2', 'price_amount' => '45000', 'usdt_price_amount' => '1.8'],
                    ['minimum_quantity' => '5', 'price_amount' => '40000', 'usdt_price_amount' => '1.6'],
                ],
            ])
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::query()->where('name', 'Tiered Product')->firstOrFail();
        $expectedInitialTiers = [
            ['minimum_quantity' => 2, 'price_amount' => 45000, 'usdt_price_amount' => 1_800_000],
            ['minimum_quantity' => 5, 'price_amount' => 40000, 'usdt_price_amount' => 1_600_000],
        ];
        $this->assertSame(
            $expectedInitialTiers,
            $product->quantityPriceTiers()
                ->get(['minimum_quantity', 'price_amount', 'usdt_price_amount'])
                ->map(fn ($tier): array => $tier->only(['minimum_quantity', 'price_amount', 'usdt_price_amount']))
                ->all(),
        );

        $createdAudit = AuditLog::query()->where('action', 'product.created')->latest('id')->firstOrFail();
        $this->assertSame($expectedInitialTiers, $createdAudit->new_values['quantity_price_tiers']);
        $this->assertSame(0, ProductPriceChangeEvent::query()->count());

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => 'Tiered Product',
                'price_amount' => '50000',
                'usdt_price_amount' => '2.000',
                'description' => 'Produk dengan harga bertingkat.',
                'is_active' => '1',
                'quantity_price_tiers_present' => '1',
                'quantity_price_tiers' => [
                    ['minimum_quantity' => '3', 'price_amount' => '43000', 'usdt_price_amount' => '1.7'],
                ],
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $updatedAudit = AuditLog::query()->where('action', 'product.updated')->latest('id')->firstOrFail();
        $expectedUpdatedTiers = [
            ['minimum_quantity' => 3, 'price_amount' => 43000, 'usdt_price_amount' => 1_700_000],
        ];
        $this->assertSame($expectedInitialTiers, $updatedAudit->old_values['quantity_price_tiers']);
        $this->assertSame($expectedUpdatedTiers, $updatedAudit->new_values['quantity_price_tiers']);
        $this->assertSame(0, ProductPriceChangeEvent::query()->count());

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => 'Tiered Product',
                'price_amount' => '50000',
                'usdt_price_amount' => '2',
                'description' => 'Produk dengan harga dasar.',
                'is_active' => '1',
                'quantity_price_tiers_present' => '1',
                'quantity_price_tiers' => [],
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->assertDatabaseCount('product_quantity_price_tiers', 0);
        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('quantityPriceTiers', false);
    }

    public function test_invalid_quantity_price_schedule_preserves_submitted_rows_and_persisted_state(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Persisted Tiered Product',
            'description' => 'Jadwal tersimpan.',
            'price_amount' => 50000,
            'usdt_price_amount' => 2_000_000,
        ]);
        $product->quantityPriceTiers()->createMany([
            ['minimum_quantity' => 2, 'price_amount' => 45000, 'usdt_price_amount' => 1_800_000],
        ]);

        $submittedTiers = [
            ['minimum_quantity' => '2', 'price_amount' => '44000', 'usdt_price_amount' => '1.7'],
            ['minimum_quantity' => '2', 'price_amount' => '43000', 'usdt_price_amount' => '1.6'],
        ];

        $this->actingAs($admin)
            ->followingRedirects()
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'Persisted Tiered Product',
                'price_amount' => '50000',
                'usdt_price_amount' => '2',
                'description' => 'Jadwal tersimpan.',
                'is_active' => '1',
                'quantity_price_tiers_present' => '1',
                'quantity_price_tiers' => $submittedTiers,
            ])
            ->assertOk()
            ->assertSee('\u0022productModalOpen\u0022:true', false)
            ->assertSee('Minimum quantity harus unik dan berurutan naik.')
            ->assertSee('44000')
            ->assertSee('1.7')
            ->assertSee('\u0022productUsdtPrice\u0022:\u00222\u0022', false)
            ->assertSee('productQuantityPriceTiers', false);

        $this->assertSame(
            [['minimum_quantity' => 2, 'price_amount' => 45000, 'usdt_price_amount' => 1_800_000]],
            $product->fresh()->quantityPriceTiers()
                ->get(['minimum_quantity', 'price_amount', 'usdt_price_amount'])
                ->map(fn ($tier): array => $tier->only(['minimum_quantity', 'price_amount', 'usdt_price_amount']))
                ->all(),
        );
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_quantity_price_tiers_require_complete_usdt_pairing_and_strict_price_descent(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'USDT Tier Validation',
            'description' => 'Validasi jadwal.',
            'price_amount' => 50000,
            'usdt_price_amount' => 2_000_000,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => $product->name,
                'price_amount' => '50000',
                'usdt_price_amount' => '2',
                'description' => $product->description,
                'is_active' => '1',
                'quantity_price_tiers_present' => '1',
                'quantity_price_tiers' => [
                    ['minimum_quantity' => '2', 'price_amount' => '50000', 'usdt_price_amount' => '1.9'],
                ],
            ])
            ->assertSessionHasErrors('quantity_price_tiers', null, 'productUpdate');

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => $product->name,
                'price_amount' => '50000',
                'usdt_price_amount' => '2',
                'description' => $product->description,
                'is_active' => '1',
                'quantity_price_tiers_present' => '1',
                'quantity_price_tiers' => [
                    ['minimum_quantity' => '2', 'price_amount' => '45000', 'usdt_price_amount' => ''],
                ],
            ])
            ->assertSessionHasErrors('quantity_price_tiers', null, 'productUpdate');

        $this->assertDatabaseCount('product_quantity_price_tiers', 0);
        $this->assertSame(0, ProductPriceChangeEvent::query()->count());
    }

    public function test_admin_can_update_and_clear_optional_product_icon(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Icon Product',
            'icon' => '🧩',
            'description' => 'Original description.',
            'price_amount' => 25000,
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => 'Icon Product',
                'icon' => '',
                'price_amount' => '25000',
                'description' => 'Updated description.',
                'is_active' => '1',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->assertNull($product->fresh()->icon);
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
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::where('name', 'Figma Pro 1 Bulan')->firstOrFail();

        $this->assertStringStartsWith('products/', $product->image_path);
        $this->assertStringEndsWith('.webp', $product->image_path);
        Storage::disk('public')->assertExists($product->image_path);

        [$width, $height, $type] = getimagesize(Storage::disk('public')->path($product->image_path));
        $this->assertSame(IMAGETYPE_WEBP, $type);
        $this->assertSame(800, $width);
        $this->assertSame(333, $height);

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
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

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
        $this->assertSame(600, $width);
        $this->assertSame(800, $height);
    }

    public function test_admin_can_upload_separate_telegram_detail_and_channel_images(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.products.store'), [
                'name' => 'Telegram Poster Product',
                'price_amount' => '13000',
                'description' => 'Poster khusus Telegram.',
                'image' => UploadedFile::fake()->image('storefront-square.png', 800, 800),
                'telegram_image' => UploadedFile::fake()->image('telegram-landscape.png', 2400, 1000),
                'telegram_channel_image' => UploadedFile::fake()->image('channel-landscape.png', 2400, 1000),
            ])
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::query()->where('name', 'Telegram Poster Product')->firstOrFail();
        $this->assertStringStartsWith('products/', (string) $product->image_path);
        $this->assertStringStartsWith('telegram-products/', (string) $product->telegram_image_path);
        $this->assertStringStartsWith('telegram-channel-products/', (string) $product->telegram_channel_image_path);
        Storage::disk('public')->assertExists($product->image_path);
        Storage::disk('public')->assertExists($product->telegram_image_path);
        Storage::disk('public')->assertExists($product->telegram_channel_image_path);
        [$width, $height] = getimagesize(Storage::disk('public')->path($product->telegram_image_path));
        $this->assertSame(1600, $width);
        $this->assertSame(667, $height);
        [$channelWidth, $channelHeight, $channelType] = getimagesize(Storage::disk('public')->path($product->telegram_channel_image_path));
        $this->assertSame([1600, 667, IMAGETYPE_WEBP], [$channelWidth, $channelHeight, $channelType]);
        $createdAudit = AuditLog::query()->where('action', 'product.created')->sole();
        $this->assertSame($product->telegram_channel_image_path, $createdAudit->new_values['telegram_channel_image_path']);
        $this->get('/admin?tab=produk')->assertOk()->assertSee(basename($product->telegram_channel_image_path), false);
    }

    public function test_replacing_channel_image_preserves_detail_media_and_cleans_old_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Storage::disk('public')->put('telegram-channel-products/old.webp', 'old-channel-image');
        Storage::disk('public')->put('telegram-products/detail.webp', 'detail-image');
        $product = Product::factory()->create([
            'telegram_channel_image_path' => 'telegram-channel-products/old.webp',
            'telegram_image_path' => 'telegram-products/detail.webp',
        ]);
        $fields = [
            'name' => $product->name,
            'price_amount' => (string) $product->price_amount,
            'description' => $product->description,
        ];

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), $fields)
            ->assertRedirect();
        $this->assertSame('telegram-channel-products/old.webp', $product->fresh()->telegram_channel_image_path);
        Storage::disk('public')->assertExists('telegram-channel-products/old.webp');

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), $fields + [
                'telegram_channel_image' => UploadedFile::fake()->image('new-channel.png', 1200, 900),
            ])
            ->assertRedirect();

        $product->refresh();
        $this->assertStringStartsWith('telegram-channel-products/', (string) $product->telegram_channel_image_path);
        $this->assertNotSame('telegram-channel-products/old.webp', $product->telegram_channel_image_path);
        $this->assertSame('telegram-products/detail.webp', $product->telegram_image_path);
        Storage::disk('public')->assertMissing('telegram-channel-products/old.webp');
        Storage::disk('public')->assertExists($product->telegram_channel_image_path);
        Storage::disk('public')->assertExists('telegram-products/detail.webp');
        $updatedAudit = AuditLog::query()->where('action', 'product.updated')->latest('id')->firstOrFail();
        $this->assertSame('telegram-channel-products/old.webp', $updatedAudit->old_values['telegram_channel_image_path']);
        $this->assertSame($product->telegram_channel_image_path, $updatedAudit->new_values['telegram_channel_image_path']);
    }

    public function test_failed_channel_image_replacement_keeps_original_file_and_database_path(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('telegram-channel-products/old.webp', 'original');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'telegram_channel_image_path' => 'telegram-channel-products/old.webp',
        ]);
        $this->mock(AuditLogger::class)
            ->shouldReceive('log')
            ->once()
            ->andThrow(new \RuntimeException('Audit storage unavailable'));

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => 'Attempted new name',
                'price_amount' => (string) $product->price_amount,
                'description' => $product->description,
                'telegram_channel_image' => UploadedFile::fake()->image('replacement.png', 100, 100),
            ])
            ->assertStatus(500);

        $this->assertSame('telegram-channel-products/old.webp', $product->fresh()->telegram_channel_image_path);
        $this->assertNotSame('Attempted new name', $product->name);
        $this->assertSame(['telegram-channel-products/old.webp'], Storage::disk('public')->files('telegram-channel-products'));
        $this->assertSame('original', Storage::disk('public')->get('telegram-channel-products/old.webp'));
    }

    public function test_admin_can_upload_telegram_family_image(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.product-families.store'), [
                'name' => 'Netflix Family',
                'description' => 'Pilihan paket Netflix.',
                'sort_order' => '1',
                'telegram_image' => UploadedFile::fake()->image('family-telegram.png', 2400, 1000),
            ])
            ->assertRedirect();

        $family = ProductFamily::query()->where('name', 'Netflix Family')->firstOrFail();
        $this->assertStringStartsWith('telegram-product-families/', (string) $family->telegram_image_path);
        Storage::disk('public')->assertExists($family->telegram_image_path);
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
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::where('name', 'Kosong Fitur')->firstOrFail();

        $this->assertSame('Produk tanpa fitur tambahan.', $product->description);
        $this->assertNull($product->category_id);
        $this->assertSame(0, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertTrue($product->is_active);
    }

    public function test_admin_can_create_and_edit_customer_merchandising_fields_and_featured_rank(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $description = implode(' ', array_fill(0, 30, '📘 Deskripsi lengkap product dengan detail tambahan.'));
        $fulfillmentExpectation = implode(' ', array_fill(0, 30, '📦 Dikirim setelah pembayaran terverifikasi.'));
        $warrantyPolicy = implode(' ', array_fill(0, 30, '⚠️ Kebijakan penggantian dan refund untuk produk ini.'));
        $supportPolicy = implode(' ', array_fill(0, 30, '💬 Dukungan pelanggan tersedia untuk pemakaian produk.'));
        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Merchandising Product',
                'price_amount' => '75000',
                'description' => $description,
                'product_type' => 'Akun premium',
                'access_duration' => '30 hari',
                'region' => 'Indonesia',
                'account_type' => 'Private',
                'device_limit' => '1 perangkat',
                'included_items' => "Akun siap pakai\nPanduan login\n\nDukungan awal",
                'limitations' => "Tidak untuk dijual kembali\nSatu perangkat",
                'fulfillment_expectation' => $fulfillmentExpectation,
                'warranty_policy' => $warrantyPolicy,
                'support_policy' => $supportPolicy,
                'featured_rank' => '2',
                'delivery_note' => 'PRIVATE DELIVERY NOTE',
                'icon' => '🪄',
            ])
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::query()->where('name', 'Merchandising Product')->firstOrFail();
        $this->assertSame($description, $product->description);
        $this->assertSame('🪄', $product->icon);
        $this->assertSame('Akun premium', $product->product_type);
        $this->assertSame('30 hari', $product->access_duration);
        $this->assertSame('Indonesia', $product->region);
        $this->assertSame('Private', $product->account_type);
        $this->assertSame('1 perangkat', $product->device_limit);
        $this->assertSame("Akun siap pakai\nPanduan login\nDukungan awal", $product->included_items);
        $this->assertSame("Tidak untuk dijual kembali\nSatu perangkat", $product->limitations);
        $this->assertSame($fulfillmentExpectation, $product->fulfillment_expectation);
        $this->assertSame($warrantyPolicy, $product->warranty_policy);
        $this->assertSame($supportPolicy, $product->support_policy);
        $this->assertSame(2, $product->featured_rank);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'Merchandising Product',
                'price_amount' => '80000',
                'description' => 'Deskripsi customer yang diperbarui.',
                'product_type' => 'Lisensi software',
                'access_duration' => 'Lifetime',
                'region' => 'Global',
                'account_type' => 'Personal',
                'device_limit' => '2 perangkat',
                'included_items' => "Lisensi resmi\nUpdate produk",
                'limitations' => 'Tidak dapat dipindahtangankan.',
                'fulfillment_expectation' => 'Kode dikirim setelah pembayaran terverifikasi.',
                'warranty_policy' => 'Garansi aktivasi 14 hari.',
                'support_policy' => 'Support melalui Telegram pada jam operasional.',
                'featured_rank' => '1',
                'delivery_note' => 'PRIVATE UPDATED DELIVERY NOTE',
                'is_active' => '1',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $product->refresh();
        $this->assertSame('Lisensi software', $product->product_type);
        $this->assertSame('Lifetime', $product->access_duration);
        $this->assertSame('Global', $product->region);
        $this->assertSame('Personal', $product->account_type);
        $this->assertSame('2 perangkat', $product->device_limit);
        $this->assertSame("Lisensi resmi\nUpdate produk", $product->included_items);
        $this->assertSame('Tidak dapat dipindahtangankan.', $product->limitations);
        $this->assertSame('Kode dikirim setelah pembayaran terverifikasi.', $product->fulfillment_expectation);
        $this->assertSame('Garansi aktivasi 14 hari.', $product->warranty_policy);
        $this->assertSame('Support melalui Telegram pada jam operasional.', $product->support_policy);
        $this->assertSame(1, $product->featured_rank);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertDontSee('Detail yang dilihat pelanggan')
            ->assertDontSee('Pratinjau publik')
            ->assertDontSee('product-type', false)
            ->assertDontSee('product-featured-rank', false)
            ->assertDontSee('Lisensi software')
            ->assertSee('Pilihan utama #1');
    }

    public function test_basic_product_update_preserves_removed_customer_detail_values(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'product_type' => 'Akun premium',
            'access_duration' => '30 hari',
            'region' => 'Indonesia',
            'account_type' => 'Private',
            'device_limit' => '1 perangkat',
            'included_items' => 'Akun siap pakai',
            'limitations' => 'Satu perangkat',
            'fulfillment_expectation' => 'Dikirim setelah pembayaran.',
            'warranty_policy' => 'Garansi 7 hari.',
            'support_policy' => 'Support via Telegram.',
            'featured_rank' => 7,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.products.update', $product), [
                'name' => $product->name,
                'price_amount' => (string) $product->price_amount,
                'description' => 'Deskripsi terbaru.',
                'is_active' => '1',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $product->refresh();
        $this->assertSame('Akun premium', $product->product_type);
        $this->assertSame('30 hari', $product->access_duration);
        $this->assertSame('Indonesia', $product->region);
        $this->assertSame('Private', $product->account_type);
        $this->assertSame('1 perangkat', $product->device_limit);
        $this->assertSame('Akun siap pakai', $product->included_items);
        $this->assertSame('Satu perangkat', $product->limitations);
        $this->assertSame('Dikirim setelah pembayaran.', $product->fulfillment_expectation);
        $this->assertSame('Garansi 7 hari.', $product->warranty_policy);
        $this->assertSame('Support via Telegram.', $product->support_policy);
        $this->assertSame(7, $product->featured_rank);
    }

    public function test_product_merchandising_validation_rejects_invalid_lengths_and_featured_rank(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Invalid Merchandising Product',
                'price_amount' => '25000',
                'description' => 'Produk untuk validasi merchandising.',
                'product_type' => str_repeat('A', 101),
                'included_items' => implode("\n", array_fill(0, 21, 'Item')),
                'featured_rank' => '0',
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['product_type', 'included_items', 'featured_rank'], null, 'productCreate');

        $this->assertDatabaseCount('products', 0);
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
                'usage_guide_title' => 'Panduan penggunaan Netflix',
                'usage_guide_url' => '/netflix/cara-pakai',
                'is_active' => '0',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $product->refresh();
        $this->assertSame('Netflix Premium', $product->name);
        $this->assertSame($category->id, $product->category_id);
        $this->assertSame(99000, $product->price_amount);
        $this->assertFalse($product->is_active);
        $this->assertSame(7, $product->stock);
        $this->assertSame(2, $product->reserved_stock);
        $this->assertSame('Akses streaming siap pakai.', $product->description);
        $this->assertSame('Login dari perangkat pribadi lalu ganti password.', $product->delivery_note);
        $this->assertSame('Panduan penggunaan Netflix', $product->usage_guide_title);
        $this->assertSame('/netflix/cara-pakai', $product->usage_guide_url);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'product.updated',
            'subject_id' => (string) $product->id,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.products.destroy', $product))
            ->assertRedirect('/admin?tab=produk#admin-section-products');

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_editing_another_product_field_accepts_the_existing_usdt_price_precision(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'USDT Product',
            'description' => 'Original description',
            'price_amount' => 25000,
            'usdt_price_amount' => 1_000_000,
            'is_active' => true,
        ]);

        $response = $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'USDT Product',
                'price_amount' => '25000',
                'description' => 'Updated description',
                'usdt_price_amount' => '1.000000',
                'is_active' => '1',
            ]);
        $response->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $dashboard = $this->actingAs($admin)->get('/admin?tab=produk')->assertOk();
        $this->assertStringNotContainsString('1.000000', $dashboard->getContent());

        $product->refresh();
        $this->assertSame('Updated description', $product->description);
        $this->assertSame(1_000_000, $product->usdt_price_amount);
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

    public function test_product_usage_guide_requires_a_title_and_safe_url_together(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $base = [
            'name' => 'Provider Guide Validation',
            'price_amount' => '25000',
            'description' => 'Produk untuk menguji panduan provider.',
        ];

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.store'), $base + [
                'usage_guide_title' => 'Panduan provider',
                'usage_guide_url' => '',
            ])
            ->assertSessionHasErrors('usage_guide_url', null, 'productCreate');

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.store'), $base + [
                'usage_guide_title' => '',
                'usage_guide_url' => 'javascript:alert(1)',
            ])
            ->assertSessionHasErrors(['usage_guide_title', 'usage_guide_url'], null, 'productCreate');

        $this->assertDatabaseCount('products', 0);
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
            ->assertRedirect('/admin?tab=produk#product-'.$product->id)
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
            ->assertRedirect('/admin?tab=produk#product-'.$product->id)
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
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management')
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
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management');

        $this->assertDatabaseHas('categories', ['slug' => 'kategori']);
    }

    public function test_category_modal_validation_uses_category_error_bag(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=produk')
            ->post(route('admin.categories.store'), ['category_name' => ''])
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management')
            ->assertSessionHasErrors(['category_name'], null, 'categoryCreate');

        $this->followingRedirects()
            ->from('/admin?tab=produk')
            ->post(route('admin.categories.store'), ['category_name' => str_repeat('A', 121)])
            ->assertOk()
            ->assertSee('Nama kategori maksimal 120 karakter.')
            ->assertSee('\u0022productModalOpen\u0022:false', false)
            ->assertSee('\u0022categoryModalOpen\u0022:true', false)
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
            ])
            ->assertRedirect('/admin?tab=produk')
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
            ->assertRedirect('/admin?tab=produk#admin-section-products')
            ->assertSessionHas('status', 'Produk Delete Me Product diarsipkan dari dashboard.');

        $this->assertSoftDeleted('products', ['id' => $product->id]);

        $dashboard = $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertDontSee(route('admin.products.update', $product), false);

        $this->assertStringNotContainsString('delete-product@example.test|pass', $dashboard->getContent());
    }

    public function test_admin_can_create_and_update_product_with_locale_copy_variants(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Canva Pro Multi Bahasa',
                'price_amount' => '75000',
                'description' => 'Deskripsi default.',
                'description_ms' => 'Penerangan Melayu.',
                'description_en' => 'English description.',
                'warranty_policy' => 'Garansi default.',
                'warranty_policy_ms' => 'Jaminan Melayu.',
                'warranty_policy_en' => 'English refund policy.',
            ])
            ->assertRedirect('/admin?tab=produk#product-1');

        $product = Product::query()->where('name', 'Canva Pro Multi Bahasa')->firstOrFail();
        $this->assertSame('Deskripsi default.', $product->description);
        $this->assertSame('Penerangan Melayu.', $product->description_ms);
        $this->assertSame('English description.', $product->description_en);
        $this->assertSame('Garansi default.', $product->warranty_policy);
        $this->assertSame('Jaminan Melayu.', $product->warranty_policy_ms);
        $this->assertSame('English refund policy.', $product->warranty_policy_en);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.update', $product), [
                'name' => 'Canva Pro Multi Bahasa',
                'price_amount' => '80000',
                'description' => 'Deskripsi default.',
                'description_ms' => '',
                'description_en' => 'Updated English description.',
                'warranty_policy' => 'Garansi default.',
                'warranty_policy_ms' => 'Jaminan Melayu baru.',
                'warranty_policy_en' => '',
                'is_active' => '1',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $product->refresh();
        $this->assertNull($product->description_ms);
        $this->assertSame('Updated English description.', $product->description_en);
        $this->assertSame('Jaminan Melayu baru.', $product->warranty_policy_ms);
        $this->assertNull($product->warranty_policy_en);
        $this->assertSame('Deskripsi default.', $product->description);
        $this->assertSame('Garansi default.', $product->warranty_policy);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('product-description-ms-panel', false)
            ->assertSee('product-description-en-panel', false)
            ->assertSee('product-warranty-policy-ms-panel', false)
            ->assertSee('product-warranty-policy-en-panel', false)
            ->assertSee('Bahasa Melayu');
    }

    public function test_locale_variant_copy_over_the_product_detail_budget_is_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Over Budget Product',
                'price_amount' => '25000',
                'description' => 'Deskripsi default.',
                'description_en' => str_repeat('x', 2100),
                'warranty_policy_en' => str_repeat('y', 1000),
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['description_en'], null, 'productCreate');

        $this->assertDatabaseCount('products', 0);

        $this->followingRedirects()
            ->from('/admin?tab=produk')
            ->post(route('admin.products.store'), [
                'name' => 'Over Budget Product',
                'price_amount' => '25000',
                'description' => 'Deskripsi default.',
                'description_en' => str_repeat('x', 2100),
                'warranty_policy_en' => str_repeat('y', 1000),
            ])
            ->assertOk()
            ->assertSee('Product Details + Refund Policy English maksimal 3000')
            ->assertSee('\u0022productContentLocale\u0022:\u0022en\u0022', false);
    }
}
