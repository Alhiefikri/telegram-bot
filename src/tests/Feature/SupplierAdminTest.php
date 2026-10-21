<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Order;
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
use App\Services\Admin\AdminProductWorkspaceData;
use App\Services\Admin\AdminSupplierDashboardData;
use App\Services\AuditLogger;
use App\Services\Suppliers\ProductSupplierManager;
use App\Services\Suppliers\SupplierAvailability;
use App\Services\Suppliers\SupplierManager;
use App\Services\Suppliers\SupplierMoney;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\NonTransactionalDatabaseTestCase as TestCase;

final class SupplierAdminTest extends TestCase
{
    private const KEY = 'supplier-secret-canary-never-return-7a39';

    public function test_delete_removes_only_the_selected_unused_connection_and_its_private_catalog(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $other = $this->supplier(['name' => 'Keep this connection']);
        $otherCatalog = $this->item($other);
        DB::table('supplier_health_states')->insert(['supplier_id' => $supplier->id, 'error_codes' => '[]']);
        // A broken encrypted key must not prevent removal of an unused connection.
        DB::table('suppliers')->where('id', $supplier->id)->update(['api_key' => 'unreadable-ciphertext']);

        $this->delete(route('admin.suppliers.destroy', $supplier), ['version' => 1, 'confirm_delete' => 1])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'suppliers']))->assertSessionHas('status');

        $this->assertModelMissing($supplier);
        $this->assertModelMissing($catalog);
        $this->assertModelExists($other);
        $this->assertModelExists($otherCatalog);
        $this->assertDatabaseMissing('supplier_health_states', ['supplier_id' => $supplier->id]);
        $audit = AuditLog::query()->where('action', 'supplier.deleted')->sole();
        $this->assertSame((string) $supplier->id, $audit->subject_id);
        $this->assertStringNotContainsString(self::KEY, $audit->toJson());
        Http::assertNothingSent();
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertSee('Keep this connection');
    }

    public function test_delete_requires_explicit_confirmation_and_current_version(): void
    {
        $this->owner();
        $supplier = $this->supplier(['version' => 4]);
        $this->deleteJson(route('admin.suppliers.destroy', $supplier), ['version' => 4])
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_delete');
        $this->deleteJson(route('admin.suppliers.destroy', $supplier), ['version' => 3, 'confirm_delete' => 1])
            ->assertConflict()->assertJsonValidationErrors('version');
        $this->assertModelExists($supplier);
        $this->assertSame(0, AuditLog::where('action', 'supplier.deleted')->count());
    }

    public function test_delete_requires_store_operations_role_at_http_and_service_boundaries(): void
    {
        $supplier = $this->supplier();
        $actor = User::factory()->create(['role' => UserRole::Support]);
        $this->actingAs($actor)->delete(route('admin.suppliers.destroy', $supplier), ['version' => 1, 'confirm_delete' => 1])
            ->assertForbidden();
        try {
            app(SupplierManager::class)->delete($supplier, 1, $actor, null);
            $this->fail('Unauthorized connection deletion must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertModelExists($supplier);
    }

    #[DataProvider('deleteCommitmentCases')]
    public function test_delete_preserves_connections_with_reservations_or_running_sync(string $case): void
    {
        $this->owner();
        $supplier = $this->supplier(match ($case) {
            'wallet_hold' => ['reserved_balance_micros' => 1],
            'sync_lease' => ['sync_token' => (string) Str::uuid(), 'sync_lease_expires_at' => now()->addMinute()],
            default => [],
        });
        $catalog = $this->item($supplier, ['reserved_quantity' => $case === 'sku_hold' ? 1 : 0]);

        $this->delete(route('admin.suppliers.destroy', $supplier), ['version' => 1, 'confirm_delete' => 1])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'suppliers']))->assertSessionHas('error');

        $this->assertModelExists($supplier);
        $this->assertModelExists($catalog);
        $this->assertSame(0, AuditLog::where('action', 'supplier.deleted')->count());
    }

    public static function deleteCommitmentCases(): array
    {
        return [['wallet_hold'], ['sku_hold'], ['sync_lease']];
    }

    public function test_delete_blocks_inactive_product_mappings_and_preserves_product_capacity(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $product = Product::factory()->create(['supplier_available_stock' => 8, 'supplier_available_until' => now()->addMinutes(5)]);
        $source = $this->source($product, $catalog, ['is_active' => false]);
        $availableUntil = $product->supplier_available_until;

        $this->deleteJson(route('admin.suppliers.destroy', $supplier), ['version' => 1, 'confirm_delete' => 1])
            ->assertConflict()->assertJsonValidationErrors('connection');

        $this->assertModelExists($source);
        $this->assertModelExists($supplier);
        $this->assertSame(8, $product->fresh()->supplier_available_stock);
        $this->assertTrue($availableUntil->equalTo($product->fresh()->supplier_available_until));
    }

    #[DataProvider('deleteHistoryCases')]
    public function test_delete_preserves_transaction_and_retained_fallback_history(string $case): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        if ($case === 'deposit') {
            DB::table('supplier_deposits')->insert([
                'id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'idempotency_key' => 'historical-deposit',
                'account_id_snapshot' => 'historical-account', 'amount' => 5000, 'state' => 'paid',
            ]);
        } else {
            $fulfillment = $this->detachFulfillment(Product::factory()->create(), $case === 'snapshot' ? [['catalog_item_id' => $catalog->id]] : []);
            if ($case === 'reservation') {
                $fulfillment->update(['reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $catalog->id]);
            } elseif ($case === 'purchase') {
                SupplierPurchase::create([
                    'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $catalog->id,
                    'reference' => (string) Str::uuid(), 'quantity' => 1, 'expected_cost_micros' => 1000000,
                    'exchange_rate_idr' => 16000, 'state' => 'delivered',
                ]);
            } elseif ($case === 'unreadable_snapshot') {
                DB::table('supplier_fulfillments')->where('id', $fulfillment->id)->update(['sources_snapshot' => 'unreadable-ciphertext']);
            }
        }

        $this->deleteJson(route('admin.suppliers.destroy', $supplier), ['version' => 1, 'confirm_delete' => 1])
            ->assertConflict()->assertJsonValidationErrors('connection');

        $this->assertModelExists($supplier);
        $this->assertModelExists($catalog);
        $this->assertSame(0, AuditLog::where('action', 'supplier.deleted')->count());
    }

    public static function deleteHistoryCases(): array
    {
        return [['reservation'], ['purchase'], ['deposit'], ['snapshot'], ['unreadable_snapshot']];
    }

    public function test_delete_allows_expired_sync_leases_and_unrelated_fulfillment_history(): void
    {
        $this->owner();
        $supplier = $this->supplier(['sync_token' => (string) Str::uuid(), 'sync_lease_expires_at' => now()->subSecond()]);
        $catalog = $this->item($supplier);
        $otherCatalog = $this->item($this->supplier());
        $history = $this->detachFulfillment(Product::factory()->create(), [['catalog_item_id' => $otherCatalog->id]]);

        $this->deleteJson(route('admin.suppliers.destroy', $supplier), ['version' => 1, 'confirm_delete' => 1])->assertOk();

        $this->assertModelMissing($supplier);
        $this->assertModelMissing($catalog);
        $this->assertModelExists($history);
        $this->assertModelExists($otherCatalog);
    }

    public function test_delete_audit_failure_keeps_connection_and_catalog(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(SupplierManager::class)->delete($supplier, 1, $actor, null);
            $this->fail('Deletion must fail when the audit cannot be recorded.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertModelExists($supplier);
        $this->assertModelExists($catalog);
    }

    public function test_sync_rejection_keeps_safe_reason_and_shows_actionable_feedback(): void
    {
        $this->owner();
        $supplier = $this->supplier(['balance_micros' => 7000000]);
        Http::fake(fn () => Http::response(['error' => self::KEY], 401));
        $this->post(route('admin.suppliers.sync', $supplier))->assertRedirect()
            ->assertSessionHas('status', 'API key ditolak oleh supplier. Periksa key dan pastikan akses API aktif.');
        $this->assertSame('authentication', $supplier->fresh()->last_error);
        $this->assertSame(7000000, $supplier->fresh()->balance_micros);
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()
            ->assertSee('API key ditolak oleh supplier.')->assertDontSee(self::KEY);
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        Http::assertSentCount(1);
    }

    public function test_supplier_dashboard_product_read_filters_before_bounded_pagination_and_keeps_mapping_facts(): void
    {
        $this->owner();
        $supplier = $this->supplier(['name' => 'Mapped Supplier']);
        $item = $this->item($supplier, ['name' => 'Mapped private SKU']);
        $mapped = Product::factory()->create(['name' => 'Alpha mapped product', 'is_active' => true, 'price_amount' => 27500]);
        $this->source($mapped, $item, ['is_active' => true, 'priority' => 10]);
        Product::factory()->create(['name' => 'Beta draft product', 'is_active' => false]);
        Product::factory()->count(12)->create();

        $filtered = app(AdminSupplierDashboardData::class)->forTab([
            'supplier_search' => 'Alpha',
            'supplier_status' => 'active',
            'supplier_source' => (string) $supplier->id,
        ]);

        $this->assertSame(1, $filtered['products']->total());
        $this->assertSame('Alpha mapped product', $filtered['products']->sole()->name);
        $this->assertSame('Mapped Supplier', $filtered['products']->sole()->supplierSources->sole()->catalogItem->supplier->name);
        $this->assertSame(12, app(AdminSupplierDashboardData::class)->forTab()['products']->perPage());
    }

    public function test_supplier_dashboard_attributes_an_unpaid_order_hold_to_its_reservation_not_to_a_purchase(): void
    {
        $this->owner();
        $supplier = $this->supplier(['name' => 'Nastele', 'currency' => 'VND', 'balance_micros' => 138_000_000_000, 'reserved_balance_micros' => 69_000_000_000]);
        $sku = $this->item($supplier);
        $product = Product::factory()->create();
        $this->source($product, $sku, ['is_active' => true]);
        $order = Order::factory()->create(['expires_at' => now()->addHours(2)]);
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        SupplierFulfillment::create([
            'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'quantity' => 1,
            'state' => 'reserved', 'maximum_cost_idr' => 5000, 'sources_snapshot' => [],
            'reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $sku->id,
            'reserved_cost_micros' => 69_000_000_000, 'reserved_quantity' => 1,
        ]);

        $connection = app(AdminSupplierDashboardData::class)->forTab()['connections']->sole();

        $this->assertSame('69000.000000', $connection['held_balance']);
        $this->assertSame('69000.000000', $connection['available_balance']);
        $this->assertFalse($connection['has_unresolved_hold']);
        $this->assertTrue($connection['has_pending_payment_hold']);
        $this->assertSame('69000.000000', $connection['pending_payment_hold']);
        $this->assertSame(1, $connection['pending_payment_hold_count']);
        $this->assertSame($order->expires_at->toIso8601String(), $connection['pending_payment_hold_release_at']);

        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()
            ->assertSee('hold reservasi 1 pesanan yang belum dibayar sebesar 69000.000000 VND')
            ->assertDontSee('Dana ditahan oleh purchase yang belum terbukti.');
    }

    public function test_supplier_dashboard_keeps_the_unproven_purchase_warning_for_a_hold_that_is_not_an_unpaid_reservation(): void
    {
        $this->owner();
        $supplier = $this->supplier(['currency' => 'VND', 'balance_micros' => 138_000_000_000, 'reserved_balance_micros' => 69_001_000_000]);
        $sku = $this->item($supplier);
        $product = Product::factory()->create();
        $this->source($product, $sku, ['is_active' => true]);
        $unpaid = Order::factory()->create(['expires_at' => now()->addHours(2)]);
        $unpaidItem = OrderItem::factory()->create(['order_id' => $unpaid->id, 'product_id' => $product->id]);
        SupplierFulfillment::create([
            'order_id' => $unpaid->id, 'order_item_id' => $unpaidItem->id, 'quantity' => 1,
            'state' => 'reserved', 'maximum_cost_idr' => 5000, 'sources_snapshot' => [],
            'reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $sku->id,
            'reserved_cost_micros' => 69_000_000_000, 'reserved_quantity' => 1,
        ]);
        $paid = Order::factory()->create(['order_status' => OrderStatus::Processing, 'payment_status' => PaymentStatus::Paid]);
        $paidItem = OrderItem::factory()->create(['order_id' => $paid->id, 'product_id' => $product->id]);
        $fulfillment = SupplierFulfillment::create([
            'order_id' => $paid->id, 'order_item_id' => $paidItem->id, 'quantity' => 1,
            'state' => 'review', 'maximum_cost_idr' => 5000, 'sources_snapshot' => [],
            'reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $sku->id,
            'reserved_cost_micros' => 1_000_000, 'reserved_quantity' => 1,
        ]);
        $purchase = SupplierPurchase::create([
            'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $sku->id,
            'reference' => (string) Str::uuid(), 'quantity' => 1, 'expected_cost_micros' => 1_000_000,
            'exchange_rate_idr' => 1000, 'state' => 'review',
        ]);
        $fulfillment->forceFill(['current_purchase_id' => $purchase->id])->save();

        $connection = app(AdminSupplierDashboardData::class)->forTab()['connections']->sole();

        $this->assertTrue($connection['has_unresolved_hold']);
        $this->assertTrue($connection['has_pending_payment_hold']);
        $this->assertSame('69000.000000', $connection['pending_payment_hold']);
        $this->assertSame(1, $connection['pending_payment_hold_count']);

        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()
            ->assertSee('Dana ditahan oleh purchase yang belum terbukti.')
            ->assertSee('hold reservasi 1 pesanan yang belum dibayar sebesar 69000.000000 VND');
    }

    public function test_monitor_reports_an_unpaid_reservation_as_capacity_instead_of_a_supplier_debit(): void
    {
        $this->owner();
        $supplier = $this->supplier(['name' => 'Nastele', 'currency' => 'VND', 'balance_micros' => 138_000_000_000, 'reserved_balance_micros' => 69_000_000_000]);
        $sku = $this->item($supplier);
        $product = Product::factory()->create();
        $this->source($product, $sku, ['is_active' => true]);
        $order = Order::factory()->create(['expires_at' => now()->addHours(2)]);
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        SupplierFulfillment::create([
            'order_id' => $order->id, 'order_item_id' => $orderItem->id, 'quantity' => 1,
            'state' => 'reserved', 'maximum_cost_idr' => 5000, 'sources_snapshot' => [],
            'reserved_supplier_id' => $supplier->id, 'reserved_catalog_item_id' => $sku->id,
            'reserved_cost_micros' => 69_000_000_000, 'reserved_quantity' => 1,
        ]);

        $this->get(route('admin.suppliers.purchases'))->assertOk()
            ->assertSee('Kapasitas supplier direservasi untuk pesanan yang belum dibayar; belum ada pembelian')
            ->assertDontSee('Masih ada dana supplier ditahan');

        $this->get(route('admin.suppliers.purchases', ['invoice' => $order->invoice_number]))->assertOk()
            ->assertSee('Status hold supplier')
            ->assertSee('Kapasitas direservasi')
            ->assertSee('Belum ada pembelian ke supplier untuk hold ini.')
            ->assertSee('Tidak ada dana supplier yang terdebit.')
            ->assertDontSee('Ada transaksi supplier yang masih berjalan atau bukti yang perlu dicocokkan.');
    }

    public function test_inline_password_confirmation_allows_supplier_save_without_flashing_secrets(): void
    {
        $this->owner();
        Http::fake();
        $this->postJson(route('admin.password.confirm.store'), ['password' => 'password'])
            ->assertOk()->assertExactJson(['confirmed' => true])
            ->assertSessionHas('auth.password_confirmed_at');
        $this->postJson(route('admin.suppliers.store'), $this->connectionInput())
            ->assertOk()->assertJsonPath('redirect_url', route('admin.dashboard', ['tab' => 'suppliers']))
            ->assertDontSee(self::KEY);
        $this->assertSame(self::KEY, Supplier::query()->sole()->api_key);
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->assertNull(session()->get('_old_input.password'));
        Http::assertNothingSent();
    }

    public function test_wrong_inline_password_does_not_confirm_or_flash_the_password(): void
    {
        $this->owner();
        $this->postJson(route('admin.password.confirm.store'), ['password' => self::KEY])
            ->assertUnprocessable()->assertJsonValidationErrors('password')->assertDontSee(self::KEY);
        $this->assertNull(session()->get('auth.password_confirmed_at'));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        $this->assertSame(0, Supplier::query()->count());
    }

    public function test_fixed_provider_currency_is_automatic_without_name_or_currency_inputs(): void
    {
        $this->owner();
        Http::fake();
        foreach (['prodseller' => ['ProdSeller', 'USDT'], 'csxstore' => ['CSxStore', 'USD'], 'aivault' => ['AI Vault Hub', 'USD'], 'nystore' => ['NyStore Official', 'USD'], 'toolorax' => ['ToolOraX (ShopBot)', 'USD']] as $provider => [$name, $currency]) {
            $input = $this->connectionInput(['provider' => $provider]);
            unset($input['name'], $input['currency'], $input['currency_confirmed']);
            $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $input)->assertRedirect();
            $supplier = Supplier::query()->where('provider', $provider)->sole();
            $this->assertSame($name, $supplier->name);
            $this->assertSame($currency, $supplier->currency);
            $this->assertTrue($supplier->currency_confirmed);
        }
        Http::assertNothingSent();
    }

    public function test_nastele_admin_configuration_preserves_fractional_vnd_rate_and_rejects_currency_override(): void
    {
        $this->owner();
        $input = $this->connectionInput(['provider' => 'nastele', 'exchange_rate_idr' => '0.650001']);
        unset($input['name'], $input['currency'], $input['currency_confirmed']);
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.suppliers.store'), $input)->assertRedirect();
        $supplier = Supplier::query()->sole();
        $this->assertSame('VND', $supplier->currency);
        $this->assertSame(19501, SupplierMoney::costIdr(30_000_000_000, $supplier->exchange_rate_idr));
        $this->assertStringNotContainsString(self::KEY, DB::table('suppliers')->value('api_key'));
        $this->patch(route('admin.suppliers.update', $supplier), $input + ['currency' => 'USD', 'version' => 1])->assertStatus(422);
        $this->patch(route('admin.suppliers.update', $supplier), array_replace($input, ['exchange_rate_idr' => '0.0000001', 'version' => 1]))->assertStatus(422);
        $this->get(route('admin.dashboard', ['tab' => 'suppliers']))->assertOk()->assertSee('Nastele')->assertDontSee(self::KEY);
    }

    public function test_nastele_rate_band_rejects_per_usdt_values_and_accepts_six_place_vnd_precision(): void
    {
        $this->owner();
        Http::fake();
        $input = $this->connectionInput(['provider' => 'nastele', 'currency' => 'VND', 'currency_confirmed' => 1]);
        $store = fn (string $rate) => $this->withSession(['auth.password_confirmed_at' => time()])
            ->postJson(route('admin.suppliers.store'), array_replace($input, ['exchange_rate_idr' => $rate]));
        foreach (['18000', '1', '1.000001', '0.05'] as $rate) {
            $response = $store($rate)->assertStatus(422)->assertJsonValidationErrors('exchange_rate_idr');
            $this->assertStringContainsString('VND', (string) $response->json('errors.exchange_rate_idr.0'));
            $this->assertStringContainsString('USDT', (string) $response->json('errors.exchange_rate_idr.0'));
            $this->assertStringNotContainsString(self::KEY, $response->getContent());
            $this->assertSame(0, Supplier::query()->count());
        }
        $store('0.692308')->assertOk();
        $supplier = Supplier::query()->sole();
        $this->assertSame('VND', $supplier->currency);
        $this->assertSame('0.692308', $supplier->exchange_rate_idr);
        $this->assertSame(47770, SupplierMoney::costIdr(69_000_000_000, $supplier->exchange_rate_idr));
        $this->assertStringNotContainsString(self::KEY, json_encode(session()->all()));
        Http::assertNothingSent();
    }

    public function test_nastele_rate_band_applies_to_an_update_sent_outside_the_browser_form(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->supplier(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.692308']);
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->patchJson(route('admin.suppliers.update', $supplier), $this->connectionInput(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '18000', 'version' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('exchange_rate_idr');
        $this->assertSame('0.692308', $supplier->fresh()->exchange_rate_idr);
        $this->assertSame(1, (int) $supplier->fresh()->version);
        Http::assertNothingSent();
    }

    public function test_rate_bands_leave_other_provider_contracts_untouched(): void
    {
        $this->owner();
        Http::fake();
        foreach ([
            'prodseller' => ['USDT', 16000],
            'csxstore' => ['USD', '0.692308'],
        ] as $provider => [$currency, $rate]) {
            $this->withSession(['auth.password_confirmed_at' => time()])
                ->post(route('admin.suppliers.store'), $this->connectionInput(['provider' => $provider, 'currency' => $currency, 'exchange_rate_idr' => $rate]))->assertRedirect();
            $saved = Supplier::query()->where('provider', $provider)->sole();
            $this->assertSame($currency, $saved->currency);
            $this->assertSame((string) $rate, rtrim(rtrim((string) $saved->exchange_rate_idr, '0'), '.'));
        }
        $this->assertSame(2, Supplier::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_new_connection_form_prefills_no_rate_and_shows_the_vnd_band(): void
    {
        $html = view('admin.suppliers.connection-form', ['connection' => ['provider' => 'nastele', 'currency' => 'VND']])->render();

        // The stored rate field itself must never arrive prefilled with a dollar figure.
        preg_match('/<input[^>]*name="exchange_rate_idr"[^>]*>/', $html, $rateInput);
        $this->assertNotSame('', $rateInput[0] ?? '');
        $this->assertMatchesRegularExpression('/value=""/', $rateInput[0]);
        $this->assertStringNotContainsString('value="18000"', $rateInput[0]);
        $this->assertStringContainsString('x-text="rateDerivation"', $html);
        $this->assertStringContainsString('x-text="sampleDerivation"', $html);
        $this->assertStringContainsString('x-text="rateWarning"', $html);
        $this->assertStringContainsString('0.999999', $html);
        // The optional VND deposit helper must never become a submitted supplier field.
        $this->assertStringContainsString('x-model="calcVndPerUsdt"', $html);
        $this->assertStringContainsString('x-model="calcIdrPerUsdt"', $html);
        $this->assertStringContainsString('x-on:click="applyRateSuggestion()"', $html);
        $this->assertStringContainsString('x-bind:disabled="calcSuggestion === \'\'"', $html);
        $this->assertStringContainsString("providers[provider].currency === 'VND'", $html);
        // No placeholder may look like a stored value; the worked example lives in the copy.
        $this->assertStringNotContainsString('placeholder="18000"', $html);
        $this->assertStringNotContainsString('name="calc', $html);
    }

    public function test_rate_only_change_keeps_a_still_valid_observation_and_recomputes_capacity(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->supplier(['provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.650001', 'balance_micros' => 300_000_000_000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(4)]);
        $item = $this->item($supplier, ['external_id' => '43', 'currency' => 'VND', 'unit_price_micros' => 69_000_000_000, 'stock' => 20, 'synced_at' => now()]);
        $product = Product::factory()->create(['price_amount' => 60000, 'stock' => 0]);
        $this->source($product, $item, ['is_active' => true]);
        SupplierPricePolicy::query()->create(['product_id' => $product->id, 'mode' => 'manual', 'rounding_idr' => 1]);
        app(SupplierAvailability::class)->refreshProducts([$product->id]);
        $this->assertSame(4, $product->fresh()->supplier_available_stock);
        $observedAt = $supplier->fresh()->synced_at;

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->patch(route('admin.suppliers.update', $supplier), $this->connectionInput([
                'provider' => 'nastele', 'currency' => 'VND', 'exchange_rate_idr' => '0.692308', 'version' => 1,
            ]))->assertRedirect();

        $corrected = $supplier->fresh();
        $this->assertSame('0.692308', $corrected->exchange_rate_idr);
        $this->assertSame(2, (int) $corrected->version);
        $this->assertSame($observedAt?->getTimestamp(), $corrected->synced_at?->getTimestamp(), 'A rate change does not invalidate the provider observation.');
        $this->assertNotNull($corrected->sync_expires_at);
        $this->assertSame(4, $product->fresh()->supplier_available_stock, 'Capacity must be recomputed from the still valid observation.');
        $this->assertSame(47770, app(AdminProductWorkspaceData::class)->cost($item->fresh())['idr']);
        Http::assertNothingSent();
    }

    public function test_fixed_currency_cannot_be_overridden_by_a_submitted_dropdown_value(): void
    {
        $this->owner();
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('admin.suppliers.store'), $this->connectionInput(['provider' => 'csxstore', 'currency' => 'USDT']))->assertStatus(422);
        $this->assertSame(0, Supplier::query()->count());
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

    public function test_catalog_product_selector_includes_drafts_and_other_supplier_sources_but_not_deleted_or_already_mapped_products(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $item = $this->item($supplier);
        $active = Product::factory()->create(['name' => 'Gemini Pro', 'is_active' => true]);
        $draft = Product::factory()->create(['name' => 'Gemini Draft', 'is_active' => false]);
        $deleted = Product::factory()->create();
        $deleted->delete();
        $mapped = Product::factory()->create();
        $this->source($mapped, $item);
        $otherSupplier = $this->supplier(['name' => 'Second supplier']);
        $otherItem = $this->item($otherSupplier);
        $this->source($active, $otherItem);

        $response = $this->get(route('admin.suppliers.catalog', $supplier))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $options = $xpath->query('//select[@name="product_id"]/option[@value!=""]');
        $ids = [];
        foreach ($options as $option) {
            $ids[] = (int) $option->getAttribute('value');
        }
        $this->assertEqualsCanonicalizing([$active->id, $draft->id], $ids);

        $this->post(route('admin.suppliers.map', $item), [
            'product_id' => $active->id, 'supplier_version' => 1, 'version' => 0,
            'priority' => 20, 'is_active' => 0, 'equivalent_confirmed' => 1,
        ])->assertRedirect();
        $this->assertSame(2, ProductSupplierSource::query()->where('product_id', $active->id)->count());
        $this->assertSame('Gemini Pro', $active->refresh()->name);
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

    public function test_move_reorders_sources_and_rejects_stale_versions(): void
    {
        $this->owner();
        $primary = $this->supplier(['name' => 'Supplier Utama']);
        $backup = $this->supplier(['name' => 'Supplier Cadangan']);
        $primaryItem = $this->item($primary, ['external_id' => 'sku-utama']);
        $backupItem = $this->item($backup, ['external_id' => 'sku-cadangan']);
        $product = Product::factory()->create();
        $first = $this->source($product, $primaryItem, ['priority' => 10, 'is_active' => true]);
        $second = $this->source($product, $backupItem, ['priority' => 20, 'is_active' => true]);

        $this->post(route('admin.suppliers.move', $backupItem), [
            'product_id' => $product->id, 'direction' => 'up',
            'supplier_version' => (int) $backup->refresh()->version, 'version' => (int) $second->refresh()->version,
        ])->assertRedirect(route('admin.products.workspace', ['product' => $product, 'section' => 'supplier']));

        $this->assertSame(10, $second->refresh()->priority);
        $this->assertSame(20, $first->refresh()->priority);
        $this->assertSame(1, AuditLog::query()->where('action', 'supplier.source_moved')->count());

        $versions = [(int) Supplier::query()->findOrFail($backup->id)->version, (int) $second->refresh()->version, (int) $first->refresh()->version];
        $this->post(route('admin.suppliers.move', $backupItem), [
            'product_id' => $product->id, 'direction' => 'up',
            'supplier_version' => $versions[0], 'version' => $versions[1],
        ])->assertRedirect();
        $this->assertSame($versions, [(int) Supplier::query()->findOrFail($backup->id)->version, (int) $second->refresh()->version, (int) $first->refresh()->version]);

        $this->post(route('admin.suppliers.move', $backupItem), [
            'product_id' => $product->id, 'direction' => 'down',
            'supplier_version' => $versions[0], 'version' => $versions[1] - 1,
        ])->assertRedirect()->assertSessionHasErrors('version');
        $this->assertSame(10, $second->refresh()->priority);
        $this->assertSame(20, $first->refresh()->priority);
        $this->assertSame(1, AuditLog::query()->where('action', 'supplier.source_moved')->count());
    }

    public function test_private_preview_is_integer_and_manual_policy_preserves_retail(): void
    {
        $this->owner();
        $supplier = $this->supplier(['exchange_rate_idr' => 16000]);
        $item = $this->item($supplier, ['unit_price_micros' => 1250000]);
        $product = Product::factory()->create(['price_amount' => 45000]);
        $this->source($product, $item);
        $this->postJson(route('admin.products.supplier-pricing.preview', $product), $this->pricingInput(['mode' => 'hybrid', 'markup_basis_points' => 1000, 'profit_idr' => 3000, 'rounding_idr' => 1000, 'catalog_item_id' => $item->id]))->assertOk()->assertExactJson(['cost_idr' => 20000, 'retail_idr' => 23000, 'profit_idr' => 3000])->assertDontSee(self::KEY);
        $this->post(route('admin.products.supplier-pricing', $product), $this->pricingInput())->assertRedirect();
        $this->assertSame(45000, $product->refresh()->price_amount);
        $this->post(route('admin.products.supplier-pricing', $product), $this->pricingInput(['mode' => 'fixed']))->assertStatus(409);
    }

    public function test_manual_preview_reports_losses_and_break_even_without_clamping_or_writing(): void
    {
        $this->owner();
        $supplier = $this->supplier(['exchange_rate_idr' => 16000]);
        $item = $this->item($supplier, ['unit_price_micros' => 1250000]);
        $product = Product::factory()->create(['price_amount' => 19000]);
        $this->source($product, $item);
        $input = $this->pricingInput(['mode' => 'manual', 'catalog_item_id' => $item->id]);
        $this->postJson(route('admin.products.supplier-pricing.preview', $product), $input)
            ->assertOk()->assertJsonPath('profit_idr', -1000);
        $this->assertSame(19000, $product->fresh()->price_amount);
        $product->update(['price_amount' => 20000]);
        $this->postJson(route('admin.products.supplier-pricing.preview', $product), $input)
            ->assertOk()->assertJsonPath('profit_idr', 0);
        $this->assertSame(0, SupplierPricePolicy::query()->count());
        $this->assertSame(0, SupplierPurchase::query()->count());
    }

    public function test_browser_preview_shows_readable_prices_and_preserves_unsaved_settings_without_buying(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->supplier(['exchange_rate_idr' => 16000]);
        $item = $this->item($supplier, ['unit_price_micros' => 1250000]);
        $product = Product::factory()->create(['price_amount' => 45000]);
        $this->source($product, $item);

        $this->post(route('admin.products.supplier-pricing.preview', $product), $this->pricingInput(['mode' => 'manual', 'minimum_profit_idr' => 3000, 'catalog_item_id' => $item->id]))
            ->assertOk()
            ->assertDontSee(self::KEY)
            ->assertViewHas('workspace', fn (array $workspace): bool => $workspace['price_preview'] === ['cost_idr' => 20000, 'retail_idr' => 45000, 'profit_idr' => 25000]
                && $workspace['policy']['mode'] === 'manual'
                && $workspace['policy']['minimum_profit_idr'] === 3000
                && $workspace['preview_catalog_item_id'] === $item->id
            );

        $this->assertSame(45000, $product->fresh()->price_amount);
        $this->assertSame(0, SupplierPricePolicy::query()->count());
        $this->assertSame(0, SupplierPurchase::query()->count());
        Http::assertNothingSent();
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

    public function test_detach_deletes_only_mapping_and_normalizes_remaining_ranks_with_safe_audit(): void
    {
        $this->owner();
        Http::fake();
        $supplier = $this->supplier();
        $product = Product::factory()->create(['stock' => 3, 'reserved_stock' => 1, 'price_amount' => 45000]);
        $first = $this->source($product, $this->item($supplier), ['priority' => 10, 'version' => 2]);
        $item = $this->item($supplier, ['external_id' => 'remove']);
        $target = $this->source($product, $item, ['priority' => 25, 'version' => 4]);
        $last = $this->source($product, $this->item($supplier, ['external_id' => 'last']), ['priority' => 80, 'version' => 7]);
        $policy = SupplierPricePolicy::forceCreate(['product_id' => $product->id, 'mode' => 'manual', 'version' => 3]);
        $tier = ProductQuantityPriceTier::factory()->create(['product_id' => $product->id]);
        $policyBefore = $policy->fresh()->getAttributes();
        $tierBefore = $tier->fresh()->getAttributes();

        $this->delete(route('admin.suppliers.detach', $item), $this->detachInput($product, $target, $supplier))
            ->assertRedirect(route('admin.products.workspace', ['product' => $product, 'section' => 'supplier']));

        $this->assertModelMissing($target);
        $this->assertSame([10, 2], [$first->fresh()->priority, $first->fresh()->version]);
        $this->assertSame([20, 8], [$last->fresh()->priority, $last->fresh()->version]);
        $this->assertSame(2, $supplier->fresh()->version);
        $this->assertSame(self::KEY, $supplier->fresh()->api_key);
        $this->assertModelExists($item);
        $this->assertSame([3, 1, 45000], [$product->fresh()->stock, $product->fresh()->reserved_stock, $product->fresh()->price_amount]);
        $this->assertSame($policyBefore, $policy->fresh()->getAttributes());
        $this->assertSame($tierBefore, $tier->fresh()->getAttributes());
        $audit = AuditLog::where('action', 'supplier.source_detached')->sole();
        $this->assertSame((string) $target->id, $audit->subject_id);
        $this->assertSame([
            'source_id' => $target->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id,
            'catalog_item_id' => $item->id, 'priority' => 25, 'rank' => 2, 'version' => 4, 'supplier_version' => 1,
        ], $audit->old_values);
        $this->assertNull($audit->new_values);
        $this->assertStringNotContainsString(self::KEY, $audit->toJson());
        Http::assertNothingSent();
    }

    public function test_detach_last_source_refreshes_external_capacity_without_touching_local_stock_or_pricing(): void
    {
        $this->owner();
        $supplier = $this->supplier(['synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5)]);
        $item = $this->item($supplier, ['synced_at' => now()]);
        $product = Product::factory()->create(['stock' => 4, 'reserved_stock' => 1, 'price_amount' => 45000, 'supplier_available_stock' => 5, 'supplier_available_until' => now()->addMinutes(5)]);
        $source = $this->source($product, $item, ['is_active' => true]);
        $policy = SupplierPricePolicy::forceCreate(['product_id' => $product->id, 'mode' => 'fixed', 'profit_idr' => 5000, 'version' => 6]);
        $before = $policy->fresh()->getAttributes();
        $this->delete(route('admin.suppliers.detach', $item), $this->detachInput($product, $source, $supplier))->assertRedirect();
        $product->refresh();
        $this->assertSame(0, $product->supplier_available_stock);
        $this->assertSame([4, 1, 45000], [$product->stock, $product->reserved_stock, $product->price_amount]);
        $this->assertSame($before, $policy->fresh()->getAttributes());
        $this->assertSame(0, $product->supplierSources()->count());
    }

    #[DataProvider('detachHistoryCases')]
    public function test_detach_blocks_all_related_history_without_mutation(string $kind): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $product = Product::factory()->create(['supplier_available_stock' => 7, 'supplier_available_until' => now()->addMinutes(5)]);
        $source = $this->source($product, $catalog);
        $fulfillment = $this->detachFulfillment($product);
        if (str_starts_with($kind, 'purchase:')) {
            SupplierPurchase::create([
                'supplier_fulfillment_id' => $fulfillment->id, 'supplier_id' => $supplier->id,
                'supplier_catalog_item_id' => $catalog->id, 'reference' => (string) Str::uuid(),
                'quantity' => 1, 'expected_cost_micros' => 1000000, 'exchange_rate_idr' => 16000,
                'state' => substr($kind, strlen('purchase:')),
            ]);
        } elseif ($kind === 'reserved') {
            $fulfillment->update(['reserved_catalog_item_id' => $catalog->id]);
        } elseif ($kind === 'backup') {
            $fulfillment->update(['sources_snapshot' => [
                ['catalog_item_id' => $catalog->id + 1000, 'source_id' => $source->id + 1000],
                ['catalog_item_id' => $catalog->id, 'source_id' => $source->id],
            ]]);
        } elseif ($kind === 'unreadable') {
            DB::table('supplier_fulfillments')->where('id', $fulfillment->id)->update(['sources_snapshot' => self::KEY]);
        } else {
            $fulfillment->update(['sources_snapshot' => [['unexpected' => self::KEY]]]);
        }
        $before = [$product->fresh()->getAttributes(), $supplier->fresh()->getAttributes(), $source->fresh()->getAttributes(), $fulfillment->fresh()->getAttributes()];
        $response = $this->deleteJson(route('admin.suppliers.detach', $catalog), $this->detachInput($product, $source, $supplier))->assertStatus(409)->assertDontSee(self::KEY);
        if (in_array($kind, ['unreadable', 'malformed'], true)) {
            $response->assertJsonPath('message', 'Riwayat pemenuhan tidak dapat dibaca. Jangan lepas mapping; pulihkan APP_KEY/backup atau hubungi operator.');
        } else {
            $response->assertJsonPath('message', 'Mapping sudah digunakan pada pemenuhan/pembelian. Nonaktifkan saja agar riwayat dan pemulihan tetap aman.');
        }
        $this->assertSame($before, [$product->fresh()->getAttributes(), $supplier->fresh()->getAttributes(), $source->fresh()->getAttributes(), $fulfillment->fresh()->getAttributes()]);
        $this->assertSame(0, AuditLog::where('action', 'supplier.source_detached')->count());
    }

    public static function detachHistoryCases(): array
    {
        return array_map(fn ($kind): array => [$kind], ['purchase:awaiting_delivery', 'purchase:delivered', 'purchase:rejected', 'purchase:settled', 'reserved', 'backup', 'unreadable', 'malformed']);
    }

    public function test_detach_snapshot_guard_reads_later_batches_and_ignores_other_products_with_same_sku(): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $product = Product::factory()->create();
        $source = $this->source($product, $catalog);
        for ($i = 0; $i < 100; $i++) {
            $this->detachFulfillment($product);
        }
        $last = $this->detachFulfillment($product, [['catalog_item_id' => $catalog->id]]);
        $this->deleteJson(route('admin.suppliers.detach', $catalog), $this->detachInput($product, $source, $supplier))->assertStatus(409);
        $last->update(['sources_snapshot' => []]);
        $unrelated = $this->detachFulfillment(Product::factory()->create(), [['catalog_item_id' => $catalog->id]]);
        SupplierPurchase::create([
            'supplier_fulfillment_id' => $unrelated->id, 'supplier_id' => $supplier->id, 'supplier_catalog_item_id' => $catalog->id,
            'reference' => (string) Str::uuid(), 'quantity' => 1, 'expected_cost_micros' => 1000000, 'exchange_rate_idr' => 16000, 'state' => 'delivered',
        ]);
        $unreadable = $this->detachFulfillment(Product::factory()->create());
        DB::table('supplier_fulfillments')->where('id', $unreadable->id)->update(['sources_snapshot' => self::KEY]);
        $this->delete(route('admin.suppliers.detach', $catalog), $this->detachInput($product, $source, $supplier))->assertRedirect();
        $this->assertModelMissing($source);
        $this->assertModelExists($unrelated);
        $this->assertSame(1, SupplierPurchase::count());
    }

    #[DataProvider('detachStaleCases')]
    public function test_detach_revalidates_form_identity_and_versions(string $field): void
    {
        $this->owner();
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $product = Product::factory()->create();
        $source = $this->source($product, $catalog, ['version' => 5]);
        $input = $this->detachInput($product, $source, $supplier);
        $input[$field] = match ($field) {
            'product_id' => Product::factory()->create()->id,
            'source_id' => $this->source($product, $this->item($supplier, ['external_id' => 'other']))->id,
            default => 0,
        };
        $before = [$product->fresh()->getAttributes(), $supplier->fresh()->getAttributes(), $source->fresh()->getAttributes()];
        $this->deleteJson(route('admin.suppliers.detach', $catalog), $input)->assertStatus(422);
        $this->assertSame($before, [$product->fresh()->getAttributes(), $supplier->fresh()->getAttributes(), $source->fresh()->getAttributes()]);
        $this->assertSame(0, AuditLog::where('action', 'supplier.source_detached')->count());
    }

    public static function detachStaleCases(): array
    {
        return [['product_id'], ['source_id'], ['version'], ['supplier_version']];
    }

    public function test_detach_requires_store_operations_authorization_at_http_and_manager_boundaries(): void
    {
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $product = Product::factory()->create();
        $source = $this->source($product, $catalog);
        $actor = User::factory()->create(['role' => UserRole::Support]);
        $this->actingAs($actor)->delete(route('admin.suppliers.detach', $catalog), $this->detachInput($product, $source, $supplier))->assertForbidden();
        try {
            app(ProductSupplierManager::class)->detach($product, $catalog, $this->detachInput($product, $source, $supplier), $actor, null);
            $this->fail('Unauthorized manager mutation must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertModelExists($source);
        $this->assertSame(1, $supplier->fresh()->version);
    }

    public function test_detach_audit_failure_rolls_back_mapping_rank_version_and_projection(): void
    {
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $supplier = $this->supplier();
        $catalog = $this->item($supplier);
        $product = Product::factory()->create(['supplier_available_stock' => 6, 'supplier_available_until' => now()->addMinutes(5)]);
        $source = $this->source($product, $catalog);
        $before = [$product->fresh()->getAttributes(), $supplier->fresh()->getAttributes(), $source->fresh()->getAttributes()];
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()->withArgs(fn ($action): bool => $action === 'supplier.source_detached')->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(ProductSupplierManager::class)->detach($product, $catalog, $this->detachInput($product, $source, $supplier), $actor, null);
            $this->fail('Audit failure must abort deletion.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('audit unavailable', $exception->getMessage());
        }
        $this->assertSame($before, [$product->fresh()->getAttributes(), $supplier->fresh()->getAttributes(), $source->fresh()->getAttributes()]);
    }

    private function detachInput(Product $product, ProductSupplierSource $source, Supplier $supplier): array
    {
        return ['product_id' => $product->id, 'source_id' => $source->id, 'version' => $source->version, 'supplier_version' => $supplier->version];
    }

    private function detachFulfillment(Product $product, array $snapshot = []): SupplierFulfillment
    {
        $item = OrderItem::factory()->create(['product_id' => $product->id]);

        return SupplierFulfillment::create([
            'order_id' => $item->order_id, 'order_item_id' => $item->id, 'quantity' => 1,
            'state' => 'delivered', 'maximum_cost_idr' => 5000, 'sources_snapshot' => $snapshot,
        ]);
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
        return array_replace(['version' => 0, 'mode' => 'manual', 'markup_basis_points' => 0, 'profit_idr' => 0, 'minimum_profit_idr' => 0, 'rounding_idr' => 1], $overrides);
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
