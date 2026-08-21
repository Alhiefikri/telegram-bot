<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendProductStockNotifications;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\CredentialIdentity;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Admin\AdminDashboardProductsData;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardStockFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', 'admin-stock-test-fingerprint-key');
        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
    }

    public function test_account_input_and_manage_stock_expose_a_safe_structured_editor_contract(): void
    {
        config()->set('services.credentials.identity_fingerprint_key', 'admin-stock-test-fingerprint-key');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Structured Account Product',
            'delivery_note' => 'Use the product tutorial.',
            'stock' => 1,
            'reserved_stock' => 0,
        ]);

        app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([[
                'email' => 'structured@example.test',
                'password' => "multi\nline-password",
                'access_method' => CredentialAccessMethod::AuthToken->value,
                'access_value' => 'fake-auth-token-for-admin-test',
                'tutorial_override' => "Open the mailbox first.\nThen sign in.",
            ]]),
            $admin,
            '203.0.113.20',
            dispatchNotifications: false,
        );

        $payload = app(AdminDashboardProductsData::class)->forProducts(true)['products'][0]['edit_payload'];
        $item = $payload['stockItems'][0];

        $this->assertSame(CredentialFormat::StructuredAccount->value, $item['credential_format']);
        $this->assertSame('structured@example.test', $item['credential_payload']['email']);
        $this->assertSame('fake-auth-token-for-admin-test', $item['credential_payload']['access_value']);
        $this->assertSame('Use the product tutorial.', $payload['deliveryNote']);
        $this->assertSame(route('admin.products.stock.accounts.store', $product), $payload['stockAccountsUrl']);
        $this->assertTrue($item['editable']);

        $response = $this->actingAs($admin)->get('/admin?tab=produk');

        $response
            ->assertOk()
            ->assertSee('Input Cepat')
            ->assertSee('Input Akun')
            ->assertSee('Review 1 akun')
            ->assertSee('id="stock-input-quick-tab"', false)
            ->assertSee('aria-controls="stock-input-quick-panel"', false)
            ->assertSee('role="tabpanel"', false)
            ->assertSee('id="stock-input-account-panel"', false)
            ->assertSee('x-bind:aria-invalid', false)
            ->assertSee('x-bind:aria-describedby', false)
            ->assertSee('credential_format', false);
    }

    public function test_malformed_legacy_secret_is_projected_as_repair_instead_of_breaking_products_tab(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);

        ProductStockItem::query()->insert([
            'product_id' => $product->id,
            'order_item_id' => null,
            'secret' => 'sandbox-invalid-encrypted-secret',
            'credential_format' => CredentialFormat::Legacy->value,
            'credential_payload' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin?tab=produk');

        $response->assertOk();
        $item = app(AdminDashboardProductsData::class)->forProducts(true)['products'][0]['edit_payload']['stockItems'][0];

        $this->assertSame('REPAIR', $item['status']);
        $this->assertNull($item['secret']);
        $this->assertTrue($item['repair_needed']);
        $this->assertFalse($item['editable']);
    }

    public function test_available_structured_stock_item_can_be_updated_without_changing_stock_and_old_identity_is_retained(): void
    {
        config()->set('services.credentials.identity_fingerprint_key', 'admin-stock-test-fingerprint-key');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);

        app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([[
                'email' => 'before@example.test',
                'password' => 'before-password',
                'access_method' => 'otp_mail_link',
                'access_value' => 'https://mail.example.test/otp?fake=before',
            ]]),
            $admin,
            dispatchNotifications: false,
        );
        $stockItem = ProductStockItem::query()->sole();

        $this->actingAs($admin)
            ->patch(route('admin.products.stock-items.update', [$product, $stockItem]), [
                'credential_format' => CredentialFormat::StructuredAccount->value,
                'email' => 'after@example.test',
                'password' => "after\npassword",
                'access_method' => CredentialAccessMethod::AuthToken->value,
                'access_value' => 'fake-auth-token-after',
                'tutorial_override' => 'Use the updated tutorial.',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $updated = $stockItem->fresh();
        $this->assertSame('after@example.test', $updated->credential_payload['email']);
        $this->assertSame('fake-auth-token-after', $updated->credential_payload['access_value']);
        $this->assertSame(CredentialAccessMethod::AuthToken, CredentialAccessMethod::from($updated->credential_payload['access_method']));
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(2, CredentialIdentity::query()->where('identity_kind', CredentialIdentity::EMAIL)->count());
        $this->assertDatabaseCount('inventory_movements', 1);

        $audit = AuditLog::query()->where('action', 'product_stock_item.updated')->sole();
        $this->assertStringNotContainsString('before@example.test', (string) json_encode($audit->new_values));
        $this->assertStringNotContainsString('fake-auth-token-after', (string) json_encode($audit->new_values));
    }

    public function test_reserved_paid_and_retired_structured_rows_are_read_only(): void
    {
        config()->set('services.credentials.identity_fingerprint_key', 'admin-stock-test-fingerprint-key');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 3, 'reserved_stock' => 1]);
        $reservedOrder = Order::factory()->create([
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
        ]);
        $reservedItem = OrderItem::factory()->create(['order_id' => $reservedOrder->id, 'product_id' => $product->id]);
        $paidOrder = Order::factory()->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $paidOrder->payments()->create([
            'provider' => 'manual',
            'merchant_ref' => 'MR-STRUCTURED-PAID',
            'method' => 'manual',
            'amount' => 10000,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $paidItem = OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $product->id]);

        foreach ([
            ['email' => 'reserved-structured@example.test', 'order_item_id' => $reservedItem->id],
            ['email' => 'paid-structured@example.test', 'order_item_id' => $paidItem->id],
            ['email' => 'retired-structured@example.test', 'order_item_id' => null, 'retired_at' => now()],
        ] as $index => $attributes) {
            $payload = [
                'version' => 1,
                'email' => $attributes['email'],
                'password' => 'fake-password-'.$index,
                'access_method' => 'none',
                'access_value' => null,
                'tutorial_override' => null,
            ];
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $attributes['order_item_id'],
                'retired_at' => $attributes['retired_at'] ?? null,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => $payload,
                'secret' => null,
            ]);
        }

        $payload = app(AdminDashboardProductsData::class)->forProducts(true)['products'][0]['edit_payload'];

        $this->assertSame(['RETIRED', 'DIPESAN', 'PAID'], array_column($payload['stockItems'], 'status'));
        $this->assertSame([false, false, false], array_column($payload['stockItems'], 'editable'));
        $this->assertSame([null, null, null], array_column($payload['stockItems'], 'update_url'));
        $this->assertSame([null, null, null], array_column($payload['stockItems'], 'delete_url'));
    }

    public function test_structured_edit_rejects_a_registered_email_without_writing_the_new_payload(): void
    {
        config()->set('services.credentials.identity_fingerprint_key', 'admin-stock-test-fingerprint-key');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);

        foreach (['first@example.test', 'second@example.test'] as $email) {
            app(ProductStockIntake::class)->submit(
                $product,
                ProductStockIntakeSubmission::structured([[
                    'email' => $email,
                    'password' => 'fake-password-'.$email,
                    'access_method' => 'none',
                ]]),
                $admin,
                dispatchNotifications: false,
            );
        }

        $first = ProductStockItem::query()->orderBy('id')->firstOrFail();

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.stock-items.update', [$product, $first]), [
                'credential_format' => CredentialFormat::StructuredAccount->value,
                'email' => 'second@example.test',
                'password' => 'replacement-password',
                'access_method' => 'none',
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['email'], null, 'stockItemUpdate');

        $this->assertSame('first@example.test', $first->fresh()->credential_payload['email']);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(2, CredentialIdentity::query()->where('identity_kind', CredentialIdentity::EMAIL)->count());
        $this->assertSame(2, InventoryMovement::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'product.stock_adjusted')->count());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'product_stock_item.updated']);
    }

    public function test_structured_edit_validation_reopens_manage_stock_with_a_safe_error(): void
    {
        config()->set('services.credentials.identity_fingerprint_key', 'admin-stock-test-fingerprint-key');
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);

        foreach (['first@example.test', 'second@example.test'] as $email) {
            app(ProductStockIntake::class)->submit(
                $product,
                ProductStockIntakeSubmission::structured([[
                    'email' => $email,
                    'password' => 'fake-password-'.$email,
                    'access_method' => 'none',
                ]]),
                $admin,
                dispatchNotifications: false,
            );
        }

        $first = ProductStockItem::query()->orderBy('id')->firstOrFail();

        $followUp = $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->followingRedirects()
            ->patch(route('admin.products.stock-items.update', [$product, $first]), [
                'credential_format' => CredentialFormat::StructuredAccount->value,
                'email' => 'second@example.test',
                'password' => 'replacement-password',
                'access_method' => 'none',
            ])
            ->assertOk();
        $state = $followUp->viewData('dashboardState');

        $followUp
            ->assertSee('Data stok belum disimpan.', false)
            ->assertSee('Identitas kredensial sudah terdaftar dan tidak dapat digunakan ulang.', false);
        $this->assertTrue($state['manageStockModalOpen']);
        $this->assertSame($product->id, $state['manageStockPayload']['id']);
        $this->assertSame($first->id, $state['manageStockEditingItemId']);
    }

    public function test_produk_tab_renders_compact_product_cards_actions_and_stock_modal(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Owner,
        ]));

        $category = Category::factory()->create(['name' => 'Akun Premium']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Netflix 1 Bulan',
            'price_amount' => 45000,
            'stock' => 9,
            'reserved_stock' => 2,
            'is_active' => true,
        ]);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'available@example.test|pass']);
        $claimedOrder = Order::factory()->create();
        $claimedOrderItem = OrderItem::factory()->create(['order_id' => $claimedOrder->id, 'product_id' => $product->id]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $claimedOrderItem->id, 'secret' => 'claimed@example.test|pass']);

        $response = $this->get('/admin?tab=produk');

        $response
            ->assertOk()
            ->assertSee('Produk Saya')
            ->assertSee('Netflix 1 Bulan')
            ->assertSee('Akun Premium')
            ->assertSeeInOrder(['Stok', '9'])
            ->assertSeeInOrder(['Tersedia', '7'])
            ->assertSeeInOrder(['Dipesan', '2'])
            ->assertSee('IDR 45.000')
            ->assertSee('Aktif')
            ->assertSeeInOrder(['Stok', 'Ubah', 'Kelola', 'Aksi lainnya'])
            ->assertSee('data-product-actions', false)
            ->assertSee('data-product-primary-action', false)
            ->assertSee('data-product-secondary-actions', false)
            ->assertSee('data-product-more-trigger', false)
            ->assertSee('data-product-more-panel', false)
            ->assertSee('aria-expanded="false"', false)
            ->assertSee('aria-controls="product-more-actions-'.$product->id.'"', false)
            ->assertSeeInOrder(['Bagikan', 'Pause', 'Arsipkan'])
            ->assertSee('Tambah kategori')
            ->assertSee('Kelola stok')
            ->assertSee('available@example.test|pass')
            ->assertSee('claimed@example.test|pass')
            ->assertSee('bg-[#ef4444] text-white', false)
            ->assertDontSee('background: #fecaca; color: #dc2626;', false)
            ->assertDontSee('product-action-btn', false)
            ->assertDontSee('@js(', false)
            ->assertDontSee('fi-form', false)
            ->assertDontSee('fi-modal', false);

        $productCardHtml = Str::before(
            Str::after($response->getContent(), 'id="product-'.$product->id.'"'),
            '</article>'
        );

        $this->assertStringNotContainsString('🗑️ Hapus', $productCardHtml);
    }

    public function test_manage_stock_data_orders_mixed_rows_by_status_group_with_paid_history_last(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create(['name' => 'Credential Vault', 'stock' => 4, 'reserved_stock' => 1]);

        $paidOrder = Order::factory()->create([
            'invoice_number' => 'INV-PAID',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $paidOrder->payments()->create(['provider' => 'manual', 'merchant_ref' => 'MR-PAID', 'method' => 'manual', 'amount' => 10000, 'currency' => 'IDR', 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $paidItem = OrderItem::factory()->create(['order_id' => $paidOrder->id, 'product_id' => $product->id]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $paidItem->id, 'secret' => 'paid@example.test|pass']);

        $expiredOrder = Order::factory()->create([
            'invoice_number' => 'INV-STALE',
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
        ]);
        $expiredItem = OrderItem::factory()->create(['order_id' => $expiredOrder->id, 'product_id' => $product->id]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $expiredItem->id, 'secret' => 'stale@example.test|pass']);

        $reservedOrder = Order::factory()->create([
            'invoice_number' => 'INV-RESERVED',
            'order_status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'expires_at' => now()->addMinutes(5),
        ]);
        $reservedItem = OrderItem::factory()->create(['order_id' => $reservedOrder->id, 'product_id' => $product->id]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $reservedItem->id, 'secret' => 'reserved@example.test|pass']);

        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'ready@example.test|pass']);

        $payload = app(AdminDashboardProductsData::class)->forProducts(true)['products'][0]['edit_payload'];

        $this->assertSame(1, $payload['availableStockItemCount']);
        $this->assertSame(['TERSEDIA', 'DIPESAN', 'EXPIRED', 'PAID'], array_column($payload['stockItems'], 'status'));
        $this->assertSame(['ready@example.test|pass', 'reserved@example.test|pass', 'stale@example.test|pass', 'paid@example.test|pass'], array_column($payload['stockItems'], 'secret'));
        $this->assertSame(['available' => 1, 'reserved' => 1, 'paid' => 1], $payload['stockSummary']);
        $this->assertSame('INV-RESERVED', $payload['stockItems'][1]['invoice']);
        $this->assertSame('INV-STALE', $payload['stockItems'][2]['invoice']);
        $this->assertTrue($payload['stockItems'][2]['repair_needed']);
        $this->assertSame('INV-PAID', $payload['stockItems'][3]['invoice']);
    }

    public function test_manage_stock_data_keeps_latest_paid_credential_visible_after_25_item_history_limit(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Owner]));
        $product = Product::factory()->create(['name' => 'Credential History Vault', 'stock' => 26]);

        foreach (range(1, 25) as $sequence) {
            $olderPaidOrder = Order::factory()->create([
                'invoice_number' => sprintf('INV-PAID-OLDER-%02d', $sequence),
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
            ]);
            $olderPaidOrder->payments()->create(['provider' => 'manual', 'merchant_ref' => sprintf('MR-PAID-OLDER-%02d', $sequence), 'method' => 'manual', 'amount' => 10000, 'currency' => 'IDR', 'status' => PaymentStatus::Paid, 'paid_at' => now()->subMinute()]);
            $olderPaidItem = OrderItem::factory()->create(['order_id' => $olderPaidOrder->id, 'product_id' => $product->id]);
            ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $olderPaidItem->id, 'secret' => sprintf('older-paid-%02d@example.test|pass', $sequence)]);
        }

        $latestPaidOrder = Order::factory()->create([
            'invoice_number' => 'INV-PAID-LATEST',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $latestPaidOrder->payments()->create(['provider' => 'manual', 'merchant_ref' => 'MR-PAID-LATEST', 'method' => 'manual', 'amount' => 10000, 'currency' => 'IDR', 'status' => PaymentStatus::Paid, 'paid_at' => now()]);
        $latestPaidItem = OrderItem::factory()->create(['order_id' => $latestPaidOrder->id, 'product_id' => $product->id]);
        $latestPaidStockItem = ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $latestPaidItem->id, 'secret' => 'latest-paid@example.test|pass']);

        $payload = app(AdminDashboardProductsData::class)->forProducts(true)['products'][0]['edit_payload'];
        $this->assertSame(26, $payload['stockSummary']['paid']);

        $stockItemsById = array_column($payload['stockItems'], null, 'id');

        $this->assertArrayHasKey($latestPaidStockItem->id, $stockItemsById);
        $this->assertSame('INV-PAID-LATEST', $stockItemsById[$latestPaidStockItem->id]['invoice']);
    }

    public function test_dashboard_cancel_release_pending_order_restores_credential_stock(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 1]);
        $order = Order::factory()->create(['order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending]);
        $order->payments()->create(['provider' => 'manual', 'merchant_ref' => 'MR-CANCEL', 'method' => 'manual', 'amount' => 10000, 'currency' => 'IDR', 'status' => PaymentStatus::Pending]);
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id, 'quantity' => 1]);
        InventoryMovement::create(['product_id' => $product->id, 'order_id' => $order->id, 'type' => InventoryMovementType::Reservation, 'quantity' => 1, 'notes' => 'Order reservation', 'created_at' => now()]);
        ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $orderItem->id, 'secret' => 'release@example.test|pass']);

        $this->actingAs($admin)
            ->from(route('admin.orders.show', $order))
            ->post(route('admin.orders.cancel-release', $order))
            ->assertRedirect(route('admin.orders.show', $order))
            ->assertSessionHas('status', 'Order '.$order->invoice_number.' dibatalkan dan stok dilepas.');

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Failed, $order->fresh()->payment_status);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertNull(ProductStockItem::where('product_id', $product->id)->firstOrFail()->order_item_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'order.pending_cancelled_released', 'subject_id' => (string) $order->id]);
    }

    public function test_support_dashboard_does_not_receive_stock_item_secrets_or_management_urls(): void
    {
        $this->actingAs(User::factory()->create([
            'role' => UserRole::Support,
        ]));

        $category = Category::factory()->create(['name' => 'Akun Premium']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'name' => 'Spotify Premium',
            'stock' => 1,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        $stockItem = ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'support-must-not-see@example.test|pass',
        ]);

        $response = $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Spotify Premium')
            ->assertSee('Role ini hanya bisa melihat produk')
            ->assertDontSee('Tambah kategori')
            ->assertDontSee('Kelola stok');

        $content = $response->getContent();

        $this->assertStringNotContainsString('support-must-not-see@example.test|pass', $content);
        $this->assertStringNotContainsString(route('admin.products.stock-items.update', [$product, $stockItem]), $content);
        $this->assertStringNotContainsString(route('admin.products.stock-items.destroy', [$product, $stockItem]), $content);
    }

    public function test_admin_can_add_product_stock_from_compact_dashboard_modal(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Canva Pro',
            'stock' => 2,
            'reserved_stock' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "akun-1@example.test|pass1\n\nakun-2@example.test|pass2\nakun-3@example.test|pass3",
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id)
            ->assertSessionHas('status', 'Stok Canva Pro bertambah 3 item.');

        $product->refresh();
        $this->assertSame(5, $product->stock);
        $this->assertSame(1, $product->reserved_stock);
        $this->assertSame(3, ProductStockItem::where('product_id', $product->id)->count());
        $this->assertSame(
            ['akun-1@example.test|pass1', 'akun-2@example.test|pass2', 'akun-3@example.test|pass3'],
            ProductStockItem::where('product_id', $product->id)->orderBy('id')->pluck('secret')->all(),
        );
        $this->assertStringNotContainsString('akun-1@example.test', (string) InventoryMovement::where('product_id', $product->id)->latest('id')->firstOrFail()->notes);
        $this->assertStringNotContainsString('akun-1@example.test', (string) json_encode(AuditLog::where('subject_id', (string) $product->id)->latest('id')->firstOrFail()->new_values));
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::Adjustment->value,
            'quantity' => 3,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $admin->id,
            'action' => 'product.stock_adjusted',
            'subject_id' => (string) $product->id,
        ]);

        $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSeeInOrder(['Canva Pro', 'Stok', '5'])
            ->assertSeeInOrder(['Canva Pro', 'Tersedia', '4'])
            ->assertSeeInOrder(['Canva Pro', 'Dipesan', '1']);
    }

    public function test_add_stock_response_does_not_wait_for_slow_telegram_delivery(): void
    {
        Queue::fake([SendProductStockNotifications::class]);
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');
        Http::fake(function () {
            usleep(800_000);

            return Http::response(['ok' => true, 'result' => ['message_id' => 77]]);
        });

        Customer::factory()->create(['telegram_id' => 333]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);

        $startedAt = hrtime(true);

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => 'slow-telegram@example.test|pass',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $durationMs = (hrtime(true) - $startedAt) / 1_000_000;

        $this->assertLessThan(500, $durationMs, "Stock response took {$durationMs} ms while waiting for Telegram.");
        $this->assertSame(1, $product->fresh()->stock);
        $movement = InventoryMovement::query()->where('product_id', $product->id)->latest('id')->firstOrFail();
        Queue::assertPushedOn('telegram-broadcast', SendProductStockNotifications::class, fn (SendProductStockNotifications $job): bool => $job->movementId === $movement->id);
        Http::assertNothingSent();
    }

    public function test_stock_ready_broadcast_only_when_add_stock_transitions_from_zero_to_available(): void
    {
        config()->set('services.telegram.token', 'bot-token');
        config()->set('services.telegram.api_base_url', 'https://api.telegram.org');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create([
            'name' => 'Stock Ready Throttle Product',
            'stock' => 0,
            'reserved_stock' => 0,
            'is_active' => true,
        ]);
        Customer::factory()->create(['telegram_id' => 333, 'locale' => 'id']);
        Http::fake(['https://api.telegram.org/botbot-token/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => 'first-ready@example.test|pass',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => 'second-ready@example.test|pass',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $product->refresh();

        $this->assertSame(2, $product->stock);
        $this->assertSame(0, $product->reserved_stock);
        $this->assertSame(2, $product->availableStock());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.telegram.org/botbot-token/sendMessage'
            && (string) $request['chat_id'] === '333'
            && str_contains((string) $request['text'], '✅ Stok Tersedia')
            && str_contains((string) $request['text'], $product->name)
            && str_contains((string) $request['text'], '📦 Stok: 1'));
    }

    public function test_stock_modal_submission_requires_at_least_one_non_empty_row(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create(['stock' => 2]);

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "\n  \n",
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['stock_items'], null, 'stockAdjustment');

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_invalid_stock_submission_reopens_stock_modal_for_route_product_without_hidden_product_id(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create([
            'name' => 'Stock Modal Product',
            'stock' => 2,
        ]);

        $this->followingRedirects()
            ->from('/admin?tab=stats')
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => "\n  \n",
            ])
            ->assertOk()
            ->assertSee('\u0022activeTab\u0022:\u0022produk\u0022', false)
            ->assertSee('\u0022stockModalOpen\u0022:true', false)
            ->assertSee('\u0022stockProductId\u0022:'.$product->id, false)
            ->assertSee('\u0022stockProductName\u0022:\u0022Stock Modal Product\u0022', false)
            ->assertSee('Isi minimal satu baris stok.');

        $this->assertSame(2, $product->fresh()->stock);
    }

    public function test_add_stock_validation_does_not_flash_stock_item_secret_to_old_input(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create(['stock' => 2]);
        $submittedSecret = 'leaked-add-stock-secret-'.str_repeat('x', 5001);

        $this->from('/admin?tab=produk')
            ->post(route('admin.products.stock.store', $product), [
                'stock_items' => $submittedSecret,
            ])
            ->assertRedirect(route('admin.dashboard', ['tab' => 'produk']))
            ->assertSessionHasErrors(['stock_items'], null, 'stockAdjustment');

        $oldInput = session()->getOldInput();

        $this->assertSame($product->id, $oldInput['stock_product_id'] ?? null);
        $this->assertArrayNotHasKey('stock_items', $oldInput);
        $this->assertStringNotContainsString($submittedSecret, (string) json_encode($oldInput));
    }

    public function test_edit_stock_item_validation_does_not_flash_secret_to_old_input(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'old@example.test|pass']);
        $submittedSecret = 'leaked-edit-stock-secret-'.str_repeat('x', 5001);

        $this->from('/admin?tab=produk')
            ->patch(route('admin.products.stock-items.update', [$product, $stockItem]), [
                'secret' => $submittedSecret,
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['secret'], null, 'stockItemUpdate');

        $oldInput = session()->getOldInput();

        $this->assertArrayNotHasKey('secret', $oldInput);
        $this->assertStringNotContainsString($submittedSecret, (string) json_encode($oldInput));
    }

    public function test_admin_can_edit_available_stock_item_without_changing_counts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'old@example.test|pass']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->patch(route('admin.products.stock-items.update', [$product, $stockItem]), [
                'secret' => 'new@example.test|pass',
            ])
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->assertSame('new@example.test|pass', $stockItem->fresh()->secret);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertDatabaseCount('inventory_movements', 0);

        $audit = AuditLog::where('action', 'product_stock_item.updated')->firstOrFail();
        $this->assertStringNotContainsString('old@example.test', (string) json_encode($audit->old_values));
        $this->assertStringNotContainsString('new@example.test', (string) json_encode($audit->new_values));
    }

    public function test_admin_can_delete_available_stock_item_with_atomic_count_update(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'delete-me@example.test|pass']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'keep-me@example.test|pass']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->delete(route('admin.products.stock-items.destroy', [$product, $stockItem]))
            ->assertRedirect('/admin?tab=produk#product-'.$product->id)
            ->assertSessionHas('status', 'Satu stok '.$product->name.' dihapus.');

        $this->assertDatabaseMissing('product_stock_items', ['id' => $stockItem->id]);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertDatabaseHas('inventory_movements', [
            'product_id' => $product->id,
            'type' => InventoryMovementType::Adjustment->value,
            'quantity' => -1,
        ]);

        $audit = AuditLog::where('action', 'product_stock_item.deleted')->firstOrFail();
        $this->assertStringNotContainsString('delete-me@example.test', (string) json_encode($audit->old_values));
        $this->assertStringNotContainsString('delete-me@example.test', (string) json_encode($audit->new_values));

        $dashboard = $this->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('keep-me@example.test|pass')
            ->assertSeeInOrder([$product->name, 'Stok', '1']);

        $this->assertStringNotContainsString('delete-me@example.test|pass', $dashboard->getContent());
    }

    public function test_stock_item_management_rejects_claimed_and_cross_product_rows(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 1]);
        $otherProduct = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $order = Order::factory()->create();
        $orderItem = OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $product->id]);
        $claimedItem = ProductStockItem::create(['product_id' => $product->id, 'order_item_id' => $orderItem->id, 'secret' => 'claimed@example.test|pass']);
        $crossProductItem = ProductStockItem::create(['product_id' => $otherProduct->id, 'secret' => 'other@example.test|pass']);

        $this->actingAs($admin)
            ->patch(route('admin.products.stock-items.update', [$product, $claimedItem]), [
                'secret' => 'changed@example.test|pass',
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(route('admin.products.stock-items.destroy', [$product, $claimedItem]))
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(route('admin.products.stock-items.destroy', [$product, $crossProductItem]))
            ->assertNotFound();

        $this->assertSame('claimed@example.test|pass', $claimedItem->fresh()->secret);
        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);
        $this->assertSame(1, $otherProduct->fresh()->stock);
    }

    public function test_stock_item_delete_preserves_reserved_stock_invariant(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 1]);
        $stockItem = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'blocked@example.test|pass']);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->delete(route('admin.products.stock-items.destroy', [$product, $stockItem]))
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['stock_item']);

        $this->assertDatabaseHas('product_stock_items', ['id' => $stockItem->id]);
        $this->assertSame(1, $product->fresh()->stock);
        $this->assertSame(1, $product->fresh()->reserved_stock);
    }
}
