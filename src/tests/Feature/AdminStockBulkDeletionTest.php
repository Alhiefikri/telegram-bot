<?php

namespace Tests\Feature;

use App\Enums\CredentialFormat;
use App\Models\AuditLog;
use App\Models\CredentialIdentity;
use App\Models\InventoryMovement;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminStockBulkDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_delete_is_atomic_audited_and_replay_does_not_decrement_again(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['stock' => 3, 'reserved_stock' => 0]);
        $items = collect(range(1, 3))->map(fn ($i) => ProductStockItem::create([
            'product_id' => $product->id, 'secret' => 'private-credential-'.$i,
        ]));
        $ids = $items->take(2)->pluck('id')->all();
        $url = route('admin.products.stock-items.bulk-destroy', $product);
        $this->actingAs($admin)->deleteJson($url, ['stock_item_ids' => $ids])->assertOk()->assertExactJson(['deleted_count' => 2]);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame([$items->last()->id], ProductStockItem::pluck('id')->all());
        $movement = InventoryMovement::sole();
        $this->assertSame(-2, $movement->quantity);
        $this->assertSame(3, $movement->stock_before);
        $audit = AuditLog::where('action', 'product_stock_items.bulk_deleted')->sole();
        $this->assertSame($ids, $audit->new_values['stock_item_ids']);
        $this->assertStringNotContainsString('private-credential', json_encode([$audit->old_values, $audit->new_values, $movement->toArray()]));
        $this->deleteJson($url, ['stock_item_ids' => $ids])->assertUnprocessable();
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_retired_missing_and_cross_product_selections_leave_everything_untouched(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $available = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'available']);
        $retired = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'retired', 'retired_at' => now()]);
        $foreign = ProductStockItem::create(['product_id' => Product::factory()->create()->id, 'secret' => 'foreign']);
        foreach ([$retired->id, $foreign->id, 999999] as $invalidId) {
            $this->actingAs($admin)->deleteJson(route('admin.products.stock-items.bulk-destroy', $product), [
                'stock_item_ids' => [$available->id, $invalidId],
            ])->assertUnprocessable();
            $this->assertDatabaseHas('product_stock_items', ['id' => $available->id]);
            $this->assertSame(2, $product->fresh()->stock);
            $this->assertSame(0, InventoryMovement::count());
        }
    }

    public function test_reserved_numeric_stock_prevents_partial_deletion(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 1]);
        $ids = collect(range(1, 2))->map(fn () => ProductStockItem::create(['product_id' => $product->id, 'secret' => 'private'])->id)->all();
        $this->actingAs($admin)->deleteJson(route('admin.products.stock-items.bulk-destroy', $product), ['stock_item_ids' => $ids])->assertUnprocessable();
        $this->assertSame(2, ProductStockItem::count());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_allocated_credentials_block_the_entire_batch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 1]);
        $orderItem = OrderItem::factory()->create(['product_id' => $product->id]);
        $available = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'available']);
        $allocated = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'allocated', 'order_item_id' => $orderItem->id]);
        $this->actingAs($admin)->deleteJson(route('admin.products.stock-items.bulk-destroy', $product), [
            'stock_item_ids' => [$available->id, $allocated->id],
        ])->assertUnprocessable();
        $this->assertSame(2, ProductStockItem::count());
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_structured_auth_token_history_survives_bulk_deletion(): void
    {
        Queue::fake();
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $item = ProductStockItem::create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => [
                'version' => 1, 'email' => 'private@example.test', 'password' => 'private-password',
                'access_method' => 'auth_token', 'access_value' => 'private-auth-token',
                'tutorial_override' => null,
            ],
        ]);
        $identity = CredentialIdentity::create([
            'identity_kind' => 'auth_token', 'fingerprint' => str_repeat('a', 64),
            'product_id' => $product->id, 'product_stock_item_id' => $item->id, 'first_seen_at' => now(),
        ]);
        $this->actingAs(User::factory()->create(['role' => 'owner']))
            ->deleteJson(route('admin.products.stock-items.bulk-destroy', $product), ['stock_item_ids' => [$item->id]])->assertOk();
        $this->assertDatabaseMissing('product_stock_items', ['id' => $item->id]);
        $this->assertDatabaseHas('credential_identities', ['id' => $identity->id, 'fingerprint' => str_repeat('a', 64)]);
        $this->assertSame(0, $product->fresh()->stock);
        $this->assertTrue(InventoryMovement::sole()->became_unavailable);
        $audit = AuditLog::where('action', 'product_stock_items.bulk_deleted')->sole();
        $this->assertStringNotContainsString('private', json_encode([$audit->old_values, $audit->new_values]));
    }

    public function test_input_is_bounded_distinct_and_authorized(): void
    {
        $product = Product::factory()->create(['stock' => 1]);
        $item = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'private']);
        $url = route('admin.products.stock-items.bulk-destroy', $product);
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        foreach ([[], [$item->id, $item->id], range(1, 101), ['invalid'], [-1]] as $ids) {
            $this->deleteJson($url, ['stock_item_ids' => $ids])->assertUnprocessable();
        }
        $this->actingAs(User::factory()->create(['role' => 'finance']))->deleteJson($url, ['stock_item_ids' => [$item->id]])->assertForbidden();
        $this->assertDatabaseHas('product_stock_items', ['id' => $item->id]);
    }
}
