<?php

namespace Tests\Feature;

use App\Enums\CredentialFormat;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CredentialIdentity;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeSubmission;
use App\Services\ProductStockItemManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductStockEditIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', 'stock-edit-integrity-test-key');
        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
    }

    public function test_legacy_item_rejects_structured_format_without_erasing_its_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'legacy-integrity@example.test|original-password',
        ]);
        $before = $this->snapshot($product, $item);
        $input = $this->structuredInput();

        $response = $this->actingAs($admin)->patchJson(
            route('admin.products.stock-items.update', [$product, $item]),
            ['credential_format' => CredentialFormat::StructuredAccount->value, ...$input],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['credential_format', 'secret']);
        foreach ([$input['email'], $input['password'], $input['access_value'], $item->secret] as $secret) {
            $response->assertDontSee($secret, false);
        }
        $response->assertSessionMissing('_old_input');
        $this->assertSame($before, $this->snapshot($product, $item));
        $this->assertSame('legacy-integrity@example.test|original-password', $item->fresh()->secret);
    }

    public function test_structured_item_rejects_legacy_format_even_with_otherwise_valid_fields(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $item = $this->structuredItem($product, $admin);
        $before = $this->snapshot($product, $item);
        $payload = $item->credential_payload;

        $response = $this->actingAs($admin)->patchJson(
            route('admin.products.stock-items.update', [$product, $item]),
            [
                'credential_format' => CredentialFormat::Legacy->value,
                'secret' => 'replacement-legacy-secret',
                ...$this->structuredInput(),
            ],
        );

        $response->assertUnprocessable()->assertJsonValidationErrors('credential_format');
        $response->assertDontSee('replacement-legacy-secret', false);
        $this->assertSame($before, $this->snapshot($product, $item));
        $this->assertSame($payload, $item->fresh()->credential_payload);
        $this->assertNull($item->fresh()->secret);
    }

    public function test_structured_required_fields_are_derived_from_the_persisted_format(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $item = $this->structuredItem($product, $admin);
        $before = $this->snapshot($product, $item);

        $this->actingAs($admin)->patchJson(
            route('admin.products.stock-items.update', [$product, $item]),
            ['credential_format' => CredentialFormat::Legacy->value, 'secret' => 'replacement-secret'],
        )->assertUnprocessable()->assertJsonValidationErrors([
            'credential_format', 'email', 'password', 'access_method',
        ]);

        $this->assertSame($before, $this->snapshot($product, $item));
    }

    public function test_format_validation_redirect_never_flashes_credentials(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'retained-secret']);
        $before = $this->snapshot($product, $item);
        $input = $this->structuredInput();

        $this->actingAs($admin)->from('/admin?tab=produk')->patch(
            route('admin.products.stock-items.update', [$product, $item]),
            ['credential_format' => CredentialFormat::StructuredAccount->value, ...$input],
        )->assertRedirect('/admin?tab=produk')->assertSessionHas('_old_input', [
            'stock_item_product_id' => $product->id,
            'stock_item_id' => $item->id,
        ]);

        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        foreach ([$input['email'], $input['password'], $input['access_value'], 'retained-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $session);
        }
        $this->assertSame($before, $this->snapshot($product, $item));
    }

    public function test_legacy_manager_rejects_empty_and_whitespace_secrets_without_writes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'retained-secret']);
        $before = $this->snapshot($product, $item);

        foreach (['', ' ', "\t\r\n"] as $secret) {
            try {
                app(ProductStockItemManager::class)->updateAvailableSecret($product, $item, $secret, $admin);
                $this->fail('Blank credentials must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('secret', $exception->errors());
            }

            $this->assertSame($before, $this->snapshot($product, $item));
        }
    }

    public function test_legacy_manager_checks_the_locked_format_instead_of_the_callers_stale_item(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $item = $this->structuredItem($product, $admin);
        $before = $this->snapshot($product, $item);
        $item->setAttribute('credential_format', CredentialFormat::Legacy);

        try {
            app(ProductStockItemManager::class)->updateAvailableSecret($product, $item, 'replacement-secret', $admin);
            $this->fail('The legacy writer must reject a persisted structured credential.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('credential_format', $exception->errors());
            $this->assertStringNotContainsString('replacement-secret', json_encode($exception->errors(), JSON_THROW_ON_ERROR));
        }

        $this->assertSame($before, $this->snapshot($product, $item));
    }

    public function test_allocated_and_retired_legacy_items_remain_immutable(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 1]);
        $allocatedItem = OrderItem::factory()->create(['product_id' => $product->id]);
        $manager = app(ProductStockItemManager::class);

        foreach ([
            ['order_item_id' => $allocatedItem->id],
            ['retired_at' => now()],
        ] as $attributes) {
            $item = ProductStockItem::create([
                'product_id' => $product->id,
                'secret' => 'immutable-secret',
                ...$attributes,
            ]);
            $before = $this->snapshot($product, $item);

            try {
                $manager->updateAvailableSecret($product, $item, 'replacement-secret', $admin);
                $this->fail('Allocated and retired credentials must remain immutable.');
            } catch (ModelNotFoundException $exception) {
                $this->assertSame(ProductStockItem::class, $exception->getModel());
            }

            $this->assertSame($before, $this->snapshot($product, $item));
        }
    }

    public function test_legacy_edit_without_a_submitted_format_still_updates_only_the_secret(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'old-secret']);

        $this->actingAs($admin)->patchJson(
            route('admin.products.stock-items.update', [$product, $item]),
            ['secret' => 'updated-secret'],
        )->assertOk();

        $this->assertSame('updated-secret', $item->fresh()->secret);
        $this->assertSame(CredentialFormat::Legacy, $item->fresh()->safeCredentialFormat());
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('audit_logs', 1);
        $audit = AuditLog::query()->sole();
        $this->assertSame('product_stock_item.updated', $audit->action);
        $this->assertStringNotContainsString('updated-secret', json_encode($audit->new_values, JSON_THROW_ON_ERROR));
    }

    private function structuredItem(Product $product, User $admin): ProductStockItem
    {
        app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([$this->structuredInput()]),
            $admin,
            dispatchNotifications: false,
        );

        return ProductStockItem::query()->where('product_id', $product->id)->sole();
    }

    /** @return array<string, string> */
    private function structuredInput(): array
    {
        return [
            'email' => 'integrity-account@example.test',
            'password' => 'integrity-account-password',
            'access_method' => 'auth_token',
            'access_value' => 'integrity-account-auth-token',
        ];
    }

    /** @return array<string, mixed> */
    private function snapshot(Product $product, ProductStockItem $item): array
    {
        $freshProduct = $product->fresh();

        return [
            'stock_item' => (array) DB::table('product_stock_items')->where('id', $item->id)->first(),
            'stock' => $freshProduct->stock,
            'reserved_stock' => $freshProduct->reserved_stock,
            'audit_count' => AuditLog::query()->count(),
            'movement_count' => InventoryMovement::query()->count(),
            'identity_count' => CredentialIdentity::query()->count(),
        ];
    }
}
