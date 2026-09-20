<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use App\Services\Telegram\TelegramCatalogIntents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminCategoryCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_owner_can_view_the_category_management_entry_point_and_ordered_category_list(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $alpha = Category::factory()->create(['name' => 'Alpha', 'sort_order' => 0]);
        Category::factory()->create(['name' => 'Beta', 'sort_order' => 0]);
        $hidden = Category::factory()->create(['name' => 'Hidden', 'sort_order' => 1, 'is_active' => false]);
        Product::factory()->count(2)->create(['category_id' => $alpha->id]);
        Product::factory()->create(['category_id' => $hidden->id]);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Kelola Kategori')
            ->assertSeeInOrder(['Alpha', 'Beta', 'Hidden'])
            ->assertSee('2 produk')
            ->assertSee('1 produk')
            ->assertSee('Disembunyikan');
    }

    public function test_category_management_count_includes_soft_deleted_assigned_products(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));

        $category = Category::factory()->create(['name' => 'Retained Count']);
        Product::factory()->create(['category_id' => $category->id]);
        $deletedProduct = Product::factory()->create(['category_id' => $category->id]);
        $deletedProduct->delete();

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('2 produk')
            ->assertSee('Status aktif, stok, dan reservasi produk tetap dipertahankan');
    }

    public function test_read_only_roles_do_not_see_category_mutation_controls_and_cannot_mutate_categories(): void
    {
        $user = User::factory()->create(['role' => UserRole::Support]);
        $category = Category::factory()->create();

        $this->actingAs($user)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertDontSee('Kelola Kategori')
            ->assertDontSee('Hapus');

        $this->post(route('admin.categories.store'), [
            'category_name' => 'Denied Create',
        ])->assertForbidden();

        $this->patch(route('admin.categories.update', $category), [
            'name' => 'Denied Update',
            'description' => null,
            'is_active' => '1',
            'sort_order' => '0',
        ])->assertForbidden();

        $this->delete(route('admin.categories.destroy', $category))->assertForbidden();
    }

    public function test_admin_can_create_a_trimmed_category_with_a_slug_unique_against_soft_deleted_categories(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $deleted = Category::factory()->create(['name' => 'AI Tools', 'slug' => 'ai-tools']);
        $deleted->delete();

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'category_name' => '  AI Tools  ',
                'category_description' => '  Premium tools  ',
            ])
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management')
            ->assertSessionHas('status', 'Kategori AI Tools berhasil dibuat.');

        $category = Category::query()->where('name', 'AI Tools')->firstOrFail();

        $this->assertSame('ai-tools-2', $category->slug);
        $this->assertSame('Premium tools', $category->description);
        $this->assertTrue($category->is_active);
        $this->assertSame(0, $category->sort_order);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'category.created',
            'subject_type' => Category::class,
            'subject_id' => (string) $category->id,
        ]);
    }

    public function test_category_create_rejects_array_inputs_without_coercing_them_to_strings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.categories.store'), [
                'category_name' => ['not-a-string'],
                'category_description' => ['not-a-string'],
            ])
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management')
            ->assertSessionHasErrors(['category_name', 'category_description'], null, 'categoryCreate');

        $this->assertDatabaseCount('categories', 0);
    }

    public function test_category_update_changes_allowed_fields_keeps_slug_stable_and_only_audits_actual_changes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create([
            'name' => 'Old Name',
            'slug' => 'stable-slug',
            'description' => 'Old description',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.categories.update', $category), [
                'name' => '  New Name  ',
                'description' => '  New description  ',
                'is_active' => '0',
                'sort_order' => '7',
            ])
            ->assertRedirect('/admin?tab=produk&category_form=edit&category_id='.$category->id.'#admin-category-management')
            ->assertSessionHas('status', 'Kategori New Name berhasil diperbarui.');

        $category->refresh();
        $this->assertSame('New Name', $category->name);
        $this->assertSame('stable-slug', $category->slug);
        $this->assertSame('New description', $category->description);
        $this->assertFalse($category->is_active);
        $this->assertSame(7, $category->sort_order);

        $audit = AuditLog::query()->where('action', 'category.updated')->firstOrFail();
        $this->assertSame([
            'name' => 'Old Name',
            'description' => 'Old description',
            'sort_order' => 1,
            'is_active' => true,
        ], $audit->old_values);
        $this->assertSame([
            'name' => 'New Name',
            'description' => 'New description',
            'sort_order' => 7,
            'is_active' => false,
        ], $audit->new_values);

        $this->patch(route('admin.categories.update', $category), [
            'name' => 'New Name',
            'description' => 'New description',
            'is_active' => '0',
            'sort_order' => '7',
        ])->assertRedirect('/admin?tab=produk&category_form=edit&category_id='.$category->id.'#admin-category-management');

        $this->assertSame(1, AuditLog::query()->where('action', 'category.updated')->count());
    }

    public function test_invalid_category_update_reopens_the_management_modal_with_a_dedicated_error_bag(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Needs Validation']);

        $response = $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.categories.update', $category), [
                'name' => '',
                'description' => str_repeat('x', 501),
                'is_active' => 'not-a-boolean',
                'sort_order' => '1.5',
            ]);

        $response
            ->assertRedirect('/admin?tab=produk&category_form=edit&category_id='.$category->id.'#admin-category-management')
            ->assertSessionHasErrors(['name', 'description', 'is_active', 'sort_order'], null, 'categoryUpdate');

        $this->get('/admin?tab=produk&category_form=edit&category_id='.$category->id)
            ->assertOk()
            ->assertSee('\u0022categoryModalOpen\u0022:true', false)
            ->assertSee('\u0022categoryFormMode\u0022:\u0022edit\u0022', false)
            ->assertSee('Needs Validation');
    }

    public function test_category_update_rejects_array_inputs_without_coercing_them_to_strings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->patch(route('admin.categories.update', $category), [
                'name' => ['not-a-string'],
                'description' => ['not-a-string'],
                'is_active' => '1',
                'sort_order' => '0',
            ])
            ->assertRedirect('/admin?tab=produk&category_form=edit&category_id='.$category->id.'#admin-category-management')
            ->assertSessionHasErrors(['name', 'description'], null, 'categoryUpdate');

        $this->assertSame($category->name, $category->fresh()->name);
    }

    public function test_hiding_a_category_through_admin_update_removes_it_from_storefront_and_telegram_discovery_without_deactivating_products(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Visibility Flow', 'is_active' => true]);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Visibility Flow Product',
            'is_active' => true,
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.categories.update', $category), [
                'name' => $category->name,
                'description' => $category->description,
                'is_active' => '0',
                'sort_order' => (string) $category->sort_order,
            ])
            ->assertRedirect('/admin?tab=produk&category_form=edit&category_id='.$category->id.'#admin-category-management');

        $this->assertTrue($product->fresh()->is_active);
        $this->get('/')->assertOk()->assertDontSee('Visibility Flow')->assertDontSee('Visibility Flow Product');

        config()->set('services.telegram', [
            'token' => 'bot-token',
            'api_base_url' => 'https://api.telegram.org',
            'webhook_secret' => 'secret',
            'webhook_fast_callback_ack' => false,
            'default_payment_method' => 'BRIVA',
        ]);
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        app(TelegramCatalogIntents::class)->sendCategoryCatalog($customer, '12345');

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')
            && str_contains((string) $request['text'], 'Produk masih kosong')
            && ! str_contains(json_encode($request->data()), 'Visibility Flow'));
    }

    public function test_category_delete_requires_explicit_confirmation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create();

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category))
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management')
            ->assertSessionHasErrors(['confirm_delete'], null, 'categoryDelete');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_category_delete_nulls_products_preserves_product_state_and_audits_the_affected_count(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $category = Category::factory()->create(['name' => 'Delete Me', 'slug' => 'delete-me']);
        $activeProduct = Product::factory()->create([
            'category_id' => $category->id,
            'stock' => 8,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);
        $inactiveProduct = Product::factory()->create([
            'category_id' => $category->id,
            'stock' => 3,
            'reserved_stock' => 1,
            'is_active' => false,
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.categories.destroy', $category), ['confirm_delete' => '1'])
            ->assertRedirect('/admin?tab=produk&category_form=create#admin-category-management')
            ->assertSessionHas('status', 'Kategori Delete Me dihapus. 2 produk menjadi Tanpa kategori; status aktif, stok, dan reservasi tetap dipertahankan.');

        $this->assertSoftDeleted('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('products', [
            'id' => $activeProduct->id,
            'category_id' => null,
            'stock' => 8,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('products', [
            'id' => $inactiveProduct->id,
            'category_id' => null,
            'stock' => 3,
            'reserved_stock' => 1,
            'is_active' => false,
        ]);

        $audit = AuditLog::query()->where('action', 'category.deleted')->firstOrFail();
        $this->assertSame('Delete Me', $audit->old_values['name']);
        $this->assertSame('delete-me', $audit->old_values['slug']);
        $this->assertSame(2, $audit->old_values['affected_product_count']);
        $this->assertNull($audit->new_values);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Tanpa kategori')
            ->assertDontSee('id="admin-category-'.$category->id.'"', false);
    }
}
