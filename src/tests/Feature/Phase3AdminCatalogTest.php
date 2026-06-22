<?php

namespace Tests\Feature;

use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\StockAdjustmentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class Phase3AdminCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_role_cast_and_panel_access(): void
    {
        $panel = Filament::getPanel('admin');

        foreach (UserRole::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertSame($role, $user->fresh()->role);
            $this->assertTrue($user->canAccessPanel($panel));
        }

        $this->assertFalse(User::factory()->create(['role' => null])->canAccessPanel($panel));
    }

    public function test_product_policy_by_role(): void
    {
        $policy = new ProductPolicy;
        $product = Product::factory()->create();

        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue($policy->create($user));
            $this->assertTrue($policy->update($user, $product));
            $this->assertTrue($policy->delete($user, $product));
            $this->assertTrue($policy->deleteAny($user));
            $this->assertTrue($policy->restore($user, $product));
            $this->assertTrue($policy->restoreAny($user));
            $this->assertTrue($policy->adjustStock($user, $product));
            $this->assertFalse($policy->forceDelete($user, $product));
            $this->assertFalse($policy->forceDeleteAny($user));
        }

        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue($policy->view($user, $product));
            $this->assertFalse($policy->create($user));
            $this->assertFalse($policy->update($user, $product));
            $this->assertFalse($policy->delete($user, $product));
            $this->assertFalse($policy->deleteAny($user));
            $this->assertFalse($policy->restore($user, $product));
            $this->assertFalse($policy->restoreAny($user));
            $this->assertFalse($policy->adjustStock($user, $product));
            $this->assertFalse($policy->forceDelete($user, $product));
            $this->assertFalse($policy->forceDeleteAny($user));
        }
    }

    public function test_bulk_delete_restore_and_force_delete_policy_permissions(): void
    {
        $policy = new ProductPolicy;

        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertTrue($policy->deleteAny($user));
            $this->assertTrue($policy->restoreAny($user));
            $this->assertFalse($policy->forceDeleteAny($user));
        }

        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->assertFalse($policy->deleteAny($user));
            $this->assertFalse($policy->restoreAny($user));
            $this->assertFalse($policy->forceDeleteAny($user));
        }
    }

    public function test_stock_adjustment_creates_movement_and_audit_and_rejects_invalid_results(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 2]);
        $service = app(StockAdjustmentService::class);

        $adjusted = $service->adjust($product, 3, 'cycle count', $user, '127.0.0.1');

        $this->assertSame(8, $adjusted->stock);
        $this->assertSame(2, $adjusted->reserved_stock);
        $this->assertDatabaseHas('inventory_movements', ['product_id' => $product->id, 'type' => InventoryMovementType::Adjustment->value, 'quantity' => 3]);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'product.stock_adjusted', 'subject_id' => (string) $product->id]);

        $this->expectException(\InvalidArgumentException::class);
        $service->adjust($product->fresh(), -7);
    }

    public function test_stock_adjustment_rejects_stock_below_reserved_stock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(StockAdjustmentService::class)->adjust(Product::factory()->create(['stock' => 5, 'reserved_stock' => 4]), -2);
    }

    public function test_soft_delete_and_restore_audit_logs(): void
    {
        $product = Product::factory()->create();
        $product->delete();
        $product->restore();

        $this->assertNotSoftDeleted('products', ['id' => $product->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.restored']);
    }

    public function test_product_resource_create_and_edit_do_not_mutate_stock_fields(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(CreateProduct::class)
            ->fillForm(['name' => 'A', 'description' => 'B', 'price_amount' => 1000, 'currency' => 'IDR', 'stock' => 99, 'reserved_stock' => 50, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::firstOrFail();
        $this->assertSame(0, $product->stock);
        $this->assertSame(0, $product->reserved_stock);

        $product->forceFill(['stock' => 10, 'reserved_stock' => 3])->save();

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm(['name' => 'C', 'description' => 'D', 'price_amount' => 2000, 'currency' => 'IDR', 'stock' => 1, 'reserved_stock' => 1, 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(10, $product->fresh()->stock);
        $this->assertSame(3, $product->fresh()->reserved_stock);
    }

    public function test_product_audit_visibility_relationships(): void
    {
        $product = Product::factory()->create();
        InventoryMovement::factory()->create(['product_id' => $product->id, 'type' => InventoryMovementType::Adjustment]);
        AuditLog::factory()->create(['subject_type' => Product::class, 'subject_id' => (string) $product->id, 'action' => 'product.updated']);

        $this->assertCount(1, $product->inventoryMovements);
        $this->assertCount(1, $product->auditLogs);
        $this->assertSame((string) $product->id, $product->auditLogs->first()->subject_id);
    }

    public function test_product_update_audit_old_and_new_values_are_correct(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create([
            'price_amount' => 1000,
            'image_path' => 'products/old.jpg',
            'is_active' => true,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->id])
            ->fillForm([
                'name' => $product->name,
                'description' => $product->description,
                'price_amount' => 2500,
                'currency' => 'IDR',
                'image_path' => ['products/new.jpg'],
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $audit = AuditLog::query()->where('action', 'product.updated')->firstOrFail();

        $this->assertSame((string) $product->id, $audit->subject_id);
        $this->assertSame(1000, $audit->old_values['price_amount']);
        $this->assertSame('products/old.jpg', $audit->old_values['image_path']);
        $this->assertTrue($audit->old_values['is_active']);
        $this->assertSame(2500, $audit->new_values['price_amount']);
        $this->assertSame('products/new.jpg', $audit->new_values['image_path']);
        $this->assertFalse($audit->new_values['is_active']);
    }

    public function test_bootstrap_owner_refuses_existing_owner_without_force(): void
    {
        User::factory()->create(['role' => UserRole::Owner]);

        $exitCode = Artisan::call('admin:bootstrap-owner', ['email' => 'second@example.com']);

        $this->assertSame(1, $exitCode);
        $this->assertSame(1, User::query()->where('role', UserRole::Owner)->count());
    }

    public function test_bootstrap_owner_allows_existing_owner_with_force(): void
    {
        User::factory()->create(['role' => UserRole::Owner]);

        $this->artisan('admin:bootstrap-owner', ['email' => 'second@example.com', '--force' => true])
            ->expectsQuestion('Owner name', 'Second Owner')
            ->expectsQuestion('Owner password', 'S3cure!Password')
            ->assertSuccessful();

        $this->assertSame(2, User::query()->where('role', UserRole::Owner)->count());
    }
}
