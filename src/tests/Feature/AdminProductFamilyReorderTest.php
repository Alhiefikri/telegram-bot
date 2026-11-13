<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\Admin\AdminDashboardProductsData;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class AdminProductFamilyReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_and_admin_can_save_whole_list_including_empty_families_with_audit_and_cache_invalidation(): void
    {
        $capcut = ProductFamily::factory()->create(['name' => 'Capcut', 'sort_order' => 8]);
        $canva = ProductFamily::factory()->create(['name' => 'Canva', 'sort_order' => 30]);
        $empty = ProductFamily::factory()->create(['name' => 'Empty', 'sort_order' => 4]);
        $deleted = ProductFamily::factory()->create(['sort_order' => 99]);
        $deleted->delete();
        $product = Product::factory()->create(['product_family_id' => $capcut->id, 'stock' => 5]);
        $before = $product->fresh()->getAttributes();

        foreach ([UserRole::Owner, UserRole::Admin] as $index => $role) {
            $actor = User::factory()->create(['role' => $role]);
            $ids = $index === 0 ? [$capcut->id, $canva->id, $empty->id] : [$empty->id, $capcut->id, $canva->id];
            $snapshot = $this->snapshot();
            Cache::put('storefront.response.version', 100);
            Cache::put('telegram.catalog.projection.version', 100);
            $this->actingAs($actor)->patchJson(route('admin.product-families.reorder'), [
                'family_ids' => $ids,
                'expected_order' => $snapshot,
            ])->assertOk()->assertJsonPath('families.0.id', $ids[0])->assertJsonPath('families.2.sort_order', 2);

            $this->assertSame($ids, ProductFamily::query()->catalogOrder()->pluck('id')->all());
            $this->assertSame([0, 1, 2], ProductFamily::query()->catalogOrder()->pluck('sort_order')->all());
            $audit = AuditLog::query()->where('action', 'product_family.reordered')->latest('id')->firstOrFail();
            $this->assertSame($actor->id, $audit->user_id);
            $this->assertEqualsCanonicalizing($snapshot, $audit->old_values['families']);
            $this->assertSame($ids, array_column($audit->new_values['families'], 'id'));
            $this->assertGreaterThan(100, (int) Cache::get('storefront.response.version'));
            $this->assertGreaterThan(100, (int) Cache::get('telegram.catalog.projection.version'));
        }

        $this->assertSame(99, $deleted->fresh()->sort_order);
        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    public function test_guest_finance_support_and_customer_cannot_reorder(): void
    {
        $family = ProductFamily::factory()->create(['sort_order' => 7]);
        $payload = ['family_ids' => [$family->id], 'expected_order' => $this->snapshot()];
        $this->patchJson(route('admin.product-families.reorder'), $payload)->assertRedirect(route('admin.login'));
        foreach ([UserRole::Finance, UserRole::Support, null] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->patchJson(route('admin.product-families.reorder'), $payload)->assertForbidden();
        }
        $this->assertSame(7, $family->fresh()->sort_order);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product_family.reordered']);
    }

    public function test_duplicate_malformed_and_partial_lists_never_partially_write(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $first = ProductFamily::factory()->create(['sort_order' => 7]);
        $second = ProductFamily::factory()->create(['sort_order' => 9]);
        $snapshot = $this->snapshot();
        foreach ([[$first->id, $first->id], ['invalid', $second->id]] as $ids) {
            $this->patchJson(route('admin.product-families.reorder'), ['family_ids' => $ids, 'expected_order' => $snapshot])
                ->assertUnprocessable()->assertJsonValidationErrors('family_ids.0');
        }
        foreach ([[], [$first->id], [$first->id, $second->id, 999999]] as $ids) {
            $this->patchJson(route('admin.product-families.reorder'), ['family_ids' => $ids, 'expected_order' => $snapshot])
                ->assertConflict();
        }
        $this->assertSame($snapshot, $this->snapshot());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product_family.reordered']);
    }

    public function test_new_deleted_and_concurrently_reordered_family_snapshots_are_rejected(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $first = ProductFamily::factory()->create(['sort_order' => 7]);
        $second = ProductFamily::factory()->create(['sort_order' => 9]);
        $payload = ['family_ids' => [$second->id, $first->id], 'expected_order' => $this->snapshot()];
        $new = ProductFamily::factory()->create(['sort_order' => 11]);
        $this->patchJson(route('admin.product-families.reorder'), $payload)->assertConflict();
        $new->delete();
        $second->update(['sort_order' => 5]);
        $this->patchJson(route('admin.product-families.reorder'), $payload)->assertConflict();
        $second->delete();
        $this->patchJson(route('admin.product-families.reorder'), $payload)->assertConflict();
        $this->assertSame(7, $first->fresh()->sort_order);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product_family.reordered']);
    }

    public function test_audit_failure_rolls_back_every_sort_order_write(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $first = ProductFamily::factory()->create(['sort_order' => 7]);
        $second = ProductFamily::factory()->create(['sort_order' => 9]);
        $snapshot = $this->snapshot();
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new RuntimeException('audit unavailable'));
        $this->withoutExceptionHandling();
        try {
            $this->patchJson(route('admin.product-families.reorder'), [
                'family_ids' => [$second->id, $first->id], 'expected_order' => $snapshot,
            ]);
            $this->fail('Audit failure must abort the transaction.');
        } catch (RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame($snapshot, $this->snapshot());
    }

    public function test_management_payload_contains_every_family_in_saved_order_despite_admin_browse_sort(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $last = ProductFamily::factory()->create(['name' => 'Alpha empty', 'sort_order' => 20]);
        $first = ProductFamily::factory()->create(['name' => 'Zulu', 'sort_order' => 0]);
        $tie = ProductFamily::factory()->create(['name' => 'Zulu', 'sort_order' => 0]);
        Product::factory()->create(['product_family_id' => $first->id]);
        $deleted = ProductFamily::factory()->create();
        $deleted->delete();
        $this->actingAs($admin)->get(route('admin.dashboard', ['tab' => 'produk', 'product_sort' => 'name_asc']))
            ->assertOk()->assertSee('product-family-save-order', false)->assertSee('product-family-move-after-id', false);
        $payload = app(AdminDashboardProductsData::class)->forProducts(true)['familyManagement'];
        $this->assertSame([$first->id, $tie->id, $last->id], array_column($payload['families'], 'id'));
        $this->assertSame(0, $payload['families'][2]['member_count']);
        $this->assertSame(route('admin.product-families.reorder'), $payload['reorderUrl']);
    }

    /** @return list<array{id: int, sort_order: int}> */
    private function snapshot(): array
    {
        return ProductFamily::query()->orderBy('id')->get()->map(fn (ProductFamily $family): array => [
            'id' => (int) $family->id, 'sort_order' => (int) $family->sort_order,
        ])->all();
    }
}
