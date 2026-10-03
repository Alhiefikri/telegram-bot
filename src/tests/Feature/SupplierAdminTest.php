<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuantityPriceTier;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierFulfillment;
use App\Models\SupplierPricePolicy;
use App\Models\SupplierPurchase;
use App\Models\User;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

final class SupplierAdminTest extends TestCase
{
    private const KEY = 'supplier-secret-canary-never-return-7a39';

    public function test_buymail_key_is_managed_in_dashboard_with_idr_and_no_conversion_markup(): void
    {
        $this->owner();
        $input = $this->connectionInput(['provider' => 'buymail', 'currency' => 'IDR', 'exchange_rate_idr' => 1]);
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $input)->assertRedirect();
        $supplier = Supplier::query()->sole();
        $this->assertSame('IDR', $supplier->currency);
        $this->assertSame(1, $supplier->exchange_rate_idr);
        $this->assertSame(self::KEY, $supplier->api_key);
        $this->assertStringNotContainsString(self::KEY, DB::table('suppliers')->value('api_key'));
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertSee('BuyMail')->assertDontSee(self::KEY);
        $this->patch(route('admin.suppliers.update', $supplier), array_replace($input, ['api_key' => '', 'version' => 1, 'exchange_rate_idr' => 16000]))->assertStatus(422);
        $this->assertSame(1, $supplier->fresh()->exchange_rate_idr);
    }

    public function test_buymail_webhook_secret_is_saved_privately_and_blank_updates_preserve_it(): void
    {
        $this->owner();
        $secret = 'whsec_synthetic-fixture-secret';
        $input = $this->connectionInput(['provider' => 'buymail', 'currency' => 'IDR', 'exchange_rate_idr' => 1, 'webhook_secret' => $secret]);
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $input)->assertRedirect();
        $supplier = Supplier::query()->sole();
        $this->assertSame($secret, $supplier->webhook_secret);
        $this->assertStringNotContainsString($secret, DB::table('suppliers')->value('webhook_secret'));
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertDontSee($secret)->assertSee(route('buymail.webhook', $supplier));
        $this->patch(route('admin.suppliers.update', $supplier), array_replace($input, ['api_key' => '', 'webhook_secret' => '', 'version' => 1]))->assertRedirect();
        $this->assertSame($secret, $supplier->fresh()->webhook_secret);
        $this->assertStringNotContainsString($secret, json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_only_owner_and_admin_can_manage_connections_catalog_and_monitor(): void
    {
        $supplier = $this->supplier();
        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertForbidden();
            $this->get(route('admin.suppliers.catalog', $supplier))->assertForbidden();
            $this->get(route('admin.suppliers.purchases'))->assertForbidden();
            $this->post(route('admin.suppliers.store'), $this->connectionInput())->assertForbidden();
        }
        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]));
            $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertDontSee(self::KEY);
            $this->get(route('admin.suppliers.catalog', $supplier))->assertOk()->assertDontSee(self::KEY);
        }
        $this->assertSame(1, Supplier::query()->count());
    }

    public function test_api_key_is_encrypted_hidden_and_never_echoed_or_audited(): void
    {
        $this->owner();
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput())->assertRedirect();
        $supplier = Supplier::query()->sole();
        $this->assertSame(self::KEY, $supplier->api_key);
        $this->assertStringNotContainsString(self::KEY, DB::table('suppliers')->value('api_key'));
        $this->assertArrayNotHasKey('api_key', $supplier->toArray());
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertDontSee(self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode(AuditLog::query()->get()->toArray()));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
    }

    public function test_validation_and_reauthentication_do_not_flash_supplier_secret(): void
    {
        $this->owner();
        $this->post(route('admin.suppliers.store'), $this->connectionInput(['name' => '', 'provider' => self::KEY]))->assertStatus(422)->assertDontSee(self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->post(route('admin.suppliers.store'), $this->connectionInput())->assertStatus(428)->assertDontSee(self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->withSession(['auth.password_confirmed_at' => time() - 901])->post(route('admin.suppliers.store'), $this->connectionInput())->assertStatus(428);
        $this->assertSame(0, Supplier::query()->count());
    }

    public function test_blank_secret_preserves_key_without_reauth_but_account_changes_require_fresh_confirmation(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['name' => 'Edited metadata', 'api_key' => '', 'version' => 1]))->assertRedirect();
        $this->assertSame(self::KEY, $supplier->refresh()->api_key);
        $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'replacement-secret', 'version' => 2]))->assertStatus(428)->assertDontSee('replacement-secret');
        $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => '', 'provider' => 'csxstore', 'currency' => 'USD', 'version' => 2]))->assertStatus(428);
        $this->assertSame('prodseller', $supplier->refresh()->provider);
        $this->assertSame(self::KEY, $supplier->api_key);
        $this->assertStringNotContainsString('replacement-secret', json_encode(session()->all()));
    }

    public function test_currency_confirmation_and_arbitrary_urls_fail_closed(): void
    {
        $this->owner();
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput(['provider' => 'warzone', 'currency_confirmed' => 0]))->assertStatus(422);
        $this->post(route('admin.suppliers.store'), $this->connectionInput(['base_url' => 'https://attacker.example']))->assertStatus(422);
        $this->assertSame(0, Supplier::query()->count());
    }

    public function test_credential_change_invalidates_freshness_without_erasing_balances(): void
    {
        $this->owner();
        $supplier = $this->supplier(['balance_micros' => 9000000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $product = Product::factory()->create();
        $item = $this->item($supplier);
        $this->source($product, $item);
        $product->forceFill(['supplier_available_stock' => 3, 'supplier_available_until' => now()->addMinutes(5)])->save();
        $this->withSession(['auth.password_confirmed_at' => time()])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'replacement-test-key', 'version' => 1]))->assertRedirect();
        $supplier->refresh();
        $this->assertNull($supplier->synced_at);
        $this->assertNull($supplier->sync_expires_at);
        $this->assertSame(9000000, $supplier->balance_micros);
        $this->assertSame(0, $supplier->reserved_balance_micros);
        $this->assertSame(0, $product->refresh()->supplier_available_stock);
    }

    public function test_live_commitments_block_account_and_currency_changes_but_allow_disabling(): void
    {
        $this->owner();
        $supplier = $this->supplier(['balance_micros' => 9000000, 'reserved_balance_micros' => 2000000]);
        $this->withSession(['auth.password_confirmed_at' => time()])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'other-account', 'version' => 1]))->assertStatus(409);
        $this->assertSame(self::KEY, $supplier->refresh()->api_key);
        $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => '', 'exchange_rate_idr' => 17000, 'version' => 1]))->assertStatus(409);
        $this->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => '', 'is_active' => 0, 'version' => 1]))->assertRedirect();
        $this->assertFalse($supplier->refresh()->is_active);
        $this->assertSame(2000000, $supplier->reserved_balance_micros);
    }

    public function test_order_recovery_preserves_account_identity_even_after_wallet_hold_is_settled(): void
    {
        $this->owner();
        $supplier = $this->supplier(['balance_micros' => 9000000, 'reserved_balance_micros' => 0]);
        $catalog = $this->item($supplier);
        $orderItem = OrderItem::factory()->create();
        $fulfillment = SupplierFulfillment::query()->create(['order_id' => $orderItem->order_id, 'order_item_id' => $orderItem->id, 'quantity' => 1, 'state' => 'awaiting_delivery', 'maximum_cost_idr' => 20000, 'sources_snapshot' => []]);
        SupplierPurchase::query()->create(['supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $catalog->id, 'reference' => (string) Str::uuid(), 'quantity' => 1, 'expected_cost_micros' => 1000000, 'exchange_rate_idr' => 16000, 'state' => 'awaiting_delivery']);
        $this->withSession(['auth.password_confirmed_at' => time()])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['api_key' => 'other-account', 'version' => 1]))->assertStatus(409);
        $this->assertSame(self::KEY, $supplier->refresh()->api_key);
        $this->assertSame(1, $supplier->version);
    }

    public function test_stale_connection_version_cannot_overwrite_or_emit_audit(): void
    {
        $this->owner();
        $supplier = $this->supplier(['version' => 3]);
        $this->withSession(['auth.password_confirmed_at' => time()])->patch(route('admin.suppliers.update', $supplier), $this->connectionInput(['name' => 'Stale', 'version' => 2]))->assertStatus(409)->assertDontSee(self::KEY);
        $this->assertSame('Private Supplier', $supplier->refresh()->name);
        $this->assertSame(0, AuditLog::query()->where('action', 'supplier.updated')->count());
    }

    public function test_import_is_idempotent_draft_and_uses_only_operator_copy(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $item = $this->item($supplier, ['description' => 'PRIVATE supplier raw marketing copy']);
        $input = ['supplier_version' => 1, 'name' => 'Store-owned name', 'description' => 'Editable draft copy', 'price_amount' => 25000];
        $this->post(route('admin.suppliers.import', $item), $input)->assertRedirect();
        $this->post(route('admin.suppliers.import', $item), $input)->assertRedirect();
        $product = Product::query()->sole();
        $this->assertFalse($product->is_active);
        $this->assertSame('Editable draft copy', $product->description);
        $this->assertSame(0, $product->stock);
        $this->assertSame(1, ProductSupplierSource::query()->count());
        $this->assertFalse(ProductSupplierSource::query()->sole()->is_active);
        $this->assertSame(1, AuditLog::query()->where('action', 'supplier.product_imported')->count());
    }

    public function test_mapping_requires_equivalence_and_cannot_activate_manual_sku(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $item = $this->item($supplier, ['requires_input' => true]);
        $product = Product::factory()->create();
        $input = ['product_id' => $product->id, 'supplier_version' => 1, 'version' => 0, 'priority' => 10, 'is_active' => 1];
        $this->post(route('admin.suppliers.map', $item), $input)->assertStatus(422);
        $this->post(route('admin.suppliers.map', $item), $input + ['equivalent_confirmed' => 1])->assertStatus(422);
        $input['is_active'] = 0;
        $this->post(route('admin.suppliers.map', $item), $input + ['equivalent_confirmed' => 1])->assertRedirect();
        $this->assertFalse(ProductSupplierSource::query()->sole()->is_active);
    }

    public function test_stale_mapping_and_pricing_versions_are_rejected(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $item = $this->item($supplier);
        $product = Product::factory()->create(['price_amount' => 45000]);
        $source = $this->source($product, $item, ['version' => 4, 'priority' => 25]);
        $this->post(route('admin.suppliers.map', $item), ['product_id' => $product->id, 'supplier_version' => 1, 'version' => 3, 'priority' => 1, 'is_active' => 0, 'equivalent_confirmed' => 1])->assertStatus(409);
        $this->assertSame(25, $source->refresh()->priority);
        $policy = new SupplierPricePolicy;
        $policy->forceFill(['product_id' => $product->id, 'mode' => 'manual', 'version' => 2])->save();
        $this->post(route('admin.products.supplier-pricing', $product), $this->pricingInput(['version' => 1]))->assertStatus(409);
        $this->assertSame(45000, $product->refresh()->price_amount);
    }

    public function test_private_preview_is_integer_and_manual_policy_preserves_retail(): void
    {
        $this->owner();
        $supplier = $this->supplier(['exchange_rate_idr' => 16000]);
        $item = $this->item($supplier, ['unit_price_micros' => 1250000]);
        $product = Product::factory()->create(['price_amount' => 45000]);
        $this->source($product, $item);
        $this->post(route('admin.products.supplier-pricing.preview', $product), $this->pricingInput(['mode' => 'hybrid', 'markup_basis_points' => 1000, 'profit_idr' => 3000, 'buffer_idr' => 250, 'rounding_idr' => 1000, 'catalog_item_id' => $item->id]))->assertOk()->assertExactJson(['cost_idr' => 20000, 'retail_idr' => 24000])->assertDontSee(self::KEY);
        $this->post(route('admin.products.supplier-pricing', $product), $this->pricingInput())->assertRedirect();
        $this->assertSame(45000, $product->refresh()->price_amount);
        $this->post(route('admin.products.supplier-pricing', $product), $this->pricingInput(['mode' => 'fixed']))->assertStatus(409);
    }

    public function test_manual_delivery_errors_never_flash_or_echo_credentials(): void
    {
        $this->owner();
        $item = OrderItem::factory()->create();
        $fulfillment = SupplierFulfillment::query()->create(['order_id' => $item->order_id, 'order_item_id' => $item->id, 'quantity' => 1, 'state' => 'review', 'maximum_cost_idr' => 5000, 'sources_snapshot' => []]);
        $this->post(route('admin.suppliers.operate', $fulfillment), ['action' => 'manual', 'credentials' => self::KEY])->assertStatus(428)->assertDontSee(self::KEY);
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.operate', $fulfillment), ['action' => 'manual', 'credentials' => self::KEY])->assertStatus(409)->assertDontSee(self::KEY);
        $this->post(route('admin.suppliers.operate', $fulfillment), ['action' => self::KEY, 'credentials' => self::KEY])->assertStatus(422)->assertDontSee(self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->get(route('admin.suppliers.purchases'))->assertOk()->assertDontSee(self::KEY);
        $this->assertSame('review', $fulfillment->refresh()->state);
    }

    public function test_mapping_initializes_manual_policy_and_repeated_mapping_is_noop(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $item = $this->item($supplier);
        $product = Product::factory()->create(['price_amount' => 45000]);
        $input = ['product_id' => $product->id, 'supplier_version' => 1, 'version' => 0, 'priority' => 15, 'is_active' => 1, 'equivalent_confirmed' => 1];
        $this->post(route('admin.suppliers.map', $item), $input)->assertRedirect();
        $this->assertSame('manual', SupplierPricePolicy::query()->where('product_id', $product->id)->sole()->mode);
        $input['supplier_version'] = 2;
        $input['version'] = 1;
        $this->post(route('admin.suppliers.map', $item), $input)->assertRedirect();
        $this->assertSame(2, Supplier::query()->findOrFail($supplier->id)->version);
        $this->assertSame(1, ProductSupplierSource::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'supplier.source_saved')->count());
        $this->assertSame(45000, $product->refresh()->price_amount);
    }

    public function test_automatic_policy_rejects_invalid_existing_quantity_tiers_before_commit(): void
    {
        $this->owner();
        $supplier = $this->supplier(['synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $item = $this->item($supplier, ['synced_at' => now()]);
        $product = Product::factory()->create(['price_amount' => 40000, 'usdt_price_amount' => null]);
        ProductQuantityPriceTier::factory()->create(['product_id' => $product->id, 'minimum_quantity' => 2, 'price_amount' => 20000, 'usdt_price_amount' => null]);
        $this->source($product, $item, ['is_active' => true]);
        $this->post(route('admin.products.supplier-pricing', $product), $this->pricingInput(['mode' => 'fixed']))->assertStatus(422);
        $this->assertSame(40000, $product->refresh()->price_amount);
        $this->assertSame(0, SupplierPricePolicy::query()->count());
        $this->assertSame(20000, $product->quantityPriceTiers->sole()->price_amount);
    }

    public function test_production_csrf_rejects_supplier_mutation_without_token(): void
    {
        $this->owner();
        $this->app->instance(PreventRequestForgery::class, new class($this->app, app(Encrypter::class)) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput())->assertStatus(419);
        $this->assertSame(0, Supplier::query()->count());
    }

    private function owner(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
    }

    private function connectionInput(array $overrides = []): array
    {
        return array_replace(['name' => 'Private Supplier', 'provider' => 'prodseller', 'api_key' => self::KEY, 'currency' => 'USDT', 'currency_confirmed' => 1, 'exchange_rate_idr' => 16000, 'is_active' => 1, 'low_balance_threshold_micros' => 0], $overrides);
    }

    private function pricingInput(array $overrides = []): array
    {
        return array_replace(['version' => 0, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'buffer_idr' => 0, 'rounding_idr' => 1], $overrides);
    }

    private function supplier(array $overrides = []): Supplier
    {
        $supplier = new Supplier;
        $supplier->forceFill(array_replace($this->connectionInput(), ['version' => 1, 'reserved_balance_micros' => 0], $overrides))->save();

        return $supplier;
    }

    private function item(Supplier $supplier, array $overrides = []): SupplierCatalogItem
    {
        $item = new SupplierCatalogItem;
        $item->forceFill(array_replace(['supplier_id' => $supplier->id, 'external_id' => 'private-sku-1', 'name' => 'Private SKU', 'unit_price_micros' => 1000000, 'stock' => 10, 'reserved_quantity' => 0, 'orderable' => true, 'requires_input' => false, 'price_tiers' => [], 'bulk_discounts' => []], $overrides))->save();

        return $item;
    }

    private function source(Product $product, SupplierCatalogItem $item, array $overrides = []): ProductSupplierSource
    {
        $source = new ProductSupplierSource;
        $source->forceFill(array_replace(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 100, 'is_active' => false, 'version' => 1], $overrides))->save();

        return $source;
    }
}
