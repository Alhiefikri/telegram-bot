<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Requests\Admin\UpdateProductRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\User;
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Admin\CategoryManagementService;
use App\Services\Admin\ProductFamilyCategoryCoherence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProductWorkspaceCategoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_null_category_cannot_split_a_family_and_rejects_all_product_changes(): void
    {
        $this->manager();
        $category = Category::factory()->create();
        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id]);
        $sibling = Product::factory()->create(['category_id' => $category->id, 'product_family_id' => $family->id]);
        $before = $product->fresh()->getAttributes();
        $siblingBefore = $sibling->fresh()->getAttributes();
        $auditCount = AuditLog::query()->count();

        $this->patchJson(route('admin.products.update', $product), [
            'workspace_section' => 'detail',
            'workspace_version' => app(AdminProductWorkspaceData::class)->version($product, 'detail'),
            'category_id' => null,
            'name' => 'Must not be persisted',
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($siblingBefore, $sibling->fresh()->getAttributes());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public function test_category_deleted_after_validation_is_rejected_before_product_or_audit_mutation(): void
    {
        $this->manager();
        $currentCategory = Category::factory()->create();
        $targetCategory = Category::factory()->create();
        $product = Product::factory()->create(['category_id' => $currentCategory->id]);
        $before = $product->fresh()->getAttributes();
        $auditCount = AuditLog::query()->count();
        $this->app->bind(UpdateProductRequest::class, fn () => new class extends UpdateProductRequest
        {
            protected function passedValidation(): void
            {
                app(CategoryManagementService::class)->delete(
                    Category::query()->findOrFail($this->validated('category_id')),
                    $this->attributes->get('adminUser'),
                );
            }
        });

        $this->patchJson(route('admin.products.update', $product), [
            'workspace_section' => 'detail',
            'workspace_version' => app(AdminProductWorkspaceData::class)->version($product, 'detail'),
            'category_id' => $targetCategory->id,
            'name' => 'Must not be persisted',
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->assertTrue($targetCategory->fresh()->trashed());
        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($auditCount + 1, AuditLog::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'product.updated')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'category.deleted')->count());
    }

    public function test_membership_cannot_assign_a_product_whose_current_category_is_deleted(): void
    {
        $this->manager();
        $category = Category::factory()->create();
        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create(['category_id' => $category->id]);
        $category->delete();
        $before = $product->fresh()->getAttributes();
        $auditCount = AuditLog::query()->count();

        $this->patchJson(route('admin.products.family.update', $product), [
            'workspace_version' => app(ProductFamilyCategoryCoherence::class)->membershipVersion($product),
            'product_family_id' => $family->id,
            'family_sort_order' => 3,
            'family_option_label' => 'Must not be persisted',
        ])->assertUnprocessable()->assertJsonValidationErrors('category_id');

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    #[DataProvider('membershipChanges')]
    public function test_stale_grouping_save_is_rejected_without_product_or_audit_mutation(array $changes): void
    {
        $this->manager();
        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create([
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Original grouping',
        ]);
        $version = $this->getJson(route('admin.products.workspace', $product))->assertOk()->json('versions.family');

        $saved = $this->patchJson(route('admin.products.family.update', $product), $changes + [
            'workspace_version' => $version,
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Original grouping',
        ])->assertOk();
        $this->assertNotSame($version, $saved->json('workspace.versions.family'));
        $before = $product->fresh()->getAttributes();
        $auditCount = AuditLog::query()->count();

        $this->patchJson(route('admin.products.family.update', $product), [
            'workspace_version' => $version,
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Original grouping',
        ])->assertConflict();

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertSame($auditCount, AuditLog::query()->count());
    }

    public static function membershipChanges(): array
    {
        return [
            'family assignment' => [['product_family_id' => null]],
            'sort order' => [['family_sort_order' => 2]],
            'option label' => [['family_option_label' => 'Current grouping']],
        ];
    }

    public function test_independent_detail_save_does_not_conflict_with_family_version(): void
    {
        $this->manager();
        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create();
        $versions = $this->getJson(route('admin.products.workspace', $product))->assertOk()->json('versions');

        $updated = $this->patchJson(route('admin.products.update', $product), [
            'workspace_section' => 'detail',
            'workspace_version' => $versions['detail'],
            'name' => 'Independently updated name',
        ])->assertOk();
        $this->assertSame($versions['family'], $updated->json('workspace.versions.family'));

        $this->patchJson(route('admin.products.family.update', $product), [
            'workspace_version' => $versions['family'],
            'product_family_id' => $family->id,
            'family_sort_order' => 4,
            'family_option_label' => 'New grouping',
        ])->assertOk();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Independently updated name',
            'product_family_id' => $family->id,
            'family_sort_order' => 4,
            'family_option_label' => 'New grouping',
        ]);
    }

    public function test_json_grouping_requires_a_64_character_version_while_legacy_html_remains_supported(): void
    {
        $this->manager();
        $family = ProductFamily::factory()->create();
        $product = Product::factory()->create();
        $auditCount = AuditLog::query()->count();

        $this->patchJson(route('admin.products.family.update', $product), [
            'product_family_id' => $family->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('workspace_version');
        $this->patchJson(route('admin.products.family.update', $product), [
            'workspace_version' => 'short',
            'product_family_id' => $family->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('workspace_version');
        $this->assertNull($product->fresh()->product_family_id);
        $this->assertSame($auditCount, AuditLog::query()->count());

        $this->patch(route('admin.products.family.update', $product), [
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Legacy HTML',
        ])->assertRedirect();

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'family_option_label' => 'Legacy HTML',
        ]);
    }

    private function manager(): User
    {
        Queue::fake();
        $user = User::factory()->create(['role' => UserRole::Owner]);
        $this->actingAs($user);

        return $user;
    }
}
