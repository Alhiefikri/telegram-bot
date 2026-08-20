<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\InventoryMovementType;
use App\Enums\UserRole;
use App\Jobs\SendProductStockNotifications;
use App\Models\AuditLog;
use App\Models\CredentialIdentity;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductStockIntakeOperation;
use App\Models\ProductStockItem;
use App\Models\StockNotificationEvent;
use App\Models\User;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeIdempotencyConflict;
use App\Services\ProductStockIntakeSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ProductStockIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', 'stock-intake-test-fingerprint-key');
        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
    }

    public function test_structured_one_item_intake_is_atomic_and_materializes_one_safe_restock_transition(): void
    {
        Queue::fake([SendProductStockNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $operationKey = (string) Str::uuid();

        $result = app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([
                [
                    'email' => '  Account.One@Example.test ',
                    'password' => "multi\nline-password",
                    'access_method' => 'otp_mail_link',
                    'access_value' => 'https://mail.example.test/otp?opaque=fake-otp-value',
                    'tutorial_override' => 'Open the mailbox before signing in.',
                ],
            ], $operationKey),
            $admin,
            '203.0.113.10',
        );

        $product->refresh();
        $stockItem = ProductStockItem::query()->sole();
        $movement = InventoryMovement::query()->sole();
        $audit = AuditLog::query()->sole();

        $this->assertSame(1, $result->itemCount);
        $this->assertSame(0, $result->stockBefore);
        $this->assertSame(1, $result->stockAfter);
        $this->assertSame(1, $result->availableAfter);
        $this->assertSame(1, $product->stock);
        $this->assertSame(CredentialFormat::StructuredAccount, $stockItem->credential_format);
        $this->assertSame('account.one@example.test', $stockItem->credential_payload['email']);
        $this->assertSame(CredentialAccessMethod::OtpMailLink, CredentialAccessMethod::from($stockItem->credential_payload['access_method']));
        $this->assertSame(1, CredentialIdentity::query()->where('identity_kind', CredentialIdentity::EMAIL)->count());
        $this->assertSame(InventoryMovementType::Adjustment, $movement->type);
        $this->assertSame(1, $movement->quantity);
        $this->assertSame(0, $movement->available_before);
        $this->assertStringNotContainsString('Account.One@Example.test', (string) $movement->notes);
        $this->assertStringNotContainsString('multi', (string) json_encode($audit->new_values));
        $this->assertSame(1, ProductStockIntakeOperation::query()->count());
        $this->assertSame(1, StockNotificationEvent::query()->where('inventory_movement_id', $movement->id)->count());
        Queue::assertPushedOn('telegram-broadcast', SendProductStockNotifications::class, fn (SendProductStockNotifications $job): bool => $job->movementId === $movement->id);
    }

    public function test_structured_twenty_item_intake_creates_one_movement_and_one_audit(): void
    {
        $product = Product::factory()->create(['stock' => 3, 'reserved_stock' => 1]);
        $accounts = [];
        for ($index = 1; $index <= 20; $index++) {
            $accounts[] = [
                'email' => "account-{$index}@example.test",
                'password' => "password-{$index}",
                'access_method' => 'none',
                'access_value' => null,
            ];
        }

        $result = app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured($accounts),
            User::factory()->create(['role' => UserRole::Owner]),
            '203.0.113.11',
            dispatchNotifications: false,
        );

        $this->assertSame(20, $result->itemCount);
        $this->assertSame(23, $product->fresh()->stock);
        $this->assertSame(20, ProductStockItem::query()->count());
        $this->assertSame(20, CredentialIdentity::query()->where('identity_kind', CredentialIdentity::EMAIL)->count());
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(20, InventoryMovement::query()->sole()->quantity);
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame(0, StockNotificationEvent::query()->count());
    }

    public function test_invalid_structured_submission_writes_nothing_and_returns_item_indexed_safe_errors(): void
    {
        $product = Product::factory()->create(['stock' => 4, 'reserved_stock' => 1]);

        try {
            app(ProductStockIntake::class)->submit(
                $product,
                ProductStockIntakeSubmission::structured([
                    [
                        'email' => 'not-an-email',
                        'password' => '',
                        'access_method' => 'none',
                        'access_value' => 'stale-secret-value',
                    ],
                    [
                        'email' => 'safe@example.test',
                        'password' => 'password',
                        'access_method' => 'otp_mail_link',
                        'access_value' => 'http://mail.example.test/unsafe',
                    ],
                ]),
                User::factory()->create(['role' => UserRole::Admin]),
                '203.0.113.12',
                dispatchNotifications: false,
            );
            $this->fail('Expected structured validation to fail.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $encoded = json_encode($errors);

            $this->assertArrayHasKey('accounts.0.email', $errors);
            $this->assertArrayHasKey('accounts.0.password', $errors);
            $this->assertArrayHasKey('accounts.0.access_value', $errors);
            $this->assertArrayHasKey('accounts.1.access_value', $errors);
            $this->assertStringNotContainsString('not-an-email', $encoded);
            $this->assertStringNotContainsString('stale-secret-value', $encoded);
            $this->assertStringNotContainsString('http://mail.example.test/unsafe', $encoded);
        }

        $this->assertSame(4, $product->fresh()->stock);
        $this->assertSame(0, ProductStockItem::query()->count());
        $this->assertSame(0, CredentialIdentity::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, ProductStockIntakeOperation::query()->count());
    }

    public function test_duplicate_email_and_auth_token_are_rejected_without_echoing_identity_values(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $accounts = [
            [
                'email' => 'duplicate@example.test',
                'password' => 'password-a',
                'access_method' => 'auth_token',
                'access_value' => 'shared-token',
            ],
            [
                'email' => ' DUPLICATE@example.test ',
                'password' => 'password-b',
                'access_method' => 'auth_token',
                'access_value' => 'shared-token',
            ],
        ];

        try {
            app(ProductStockIntake::class)->submit($product, ProductStockIntakeSubmission::structured($accounts), User::factory()->create(['role' => UserRole::Admin]), dispatchNotifications: false);
            $this->fail('Expected duplicate identities to fail.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            $encoded = json_encode($errors);

            $this->assertArrayHasKey('accounts.0.email', $errors);
            $this->assertArrayHasKey('accounts.1.email', $errors);
            $this->assertArrayHasKey('accounts.0.access_value', $errors);
            $this->assertArrayHasKey('accounts.1.access_value', $errors);
            $this->assertStringNotContainsString('duplicate@example.test', $encoded);
            $this->assertStringNotContainsString('shared-token', $encoded);
        }

        $this->assertSame(0, ProductStockItem::query()->count());
        $this->assertSame(0, CredentialIdentity::query()->count());
    }

    public function test_registry_conflict_survives_stock_item_deletion_and_rejects_reuse(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $submission = ProductStockIntakeSubmission::structured([
            [
                'email' => 'retained@example.test',
                'password' => 'first-password',
                'access_method' => 'none',
            ],
        ]);

        app(ProductStockIntake::class)->submit($product, $submission, $admin, dispatchNotifications: false);
        $stockItem = ProductStockItem::query()->sole();
        $stockItem->delete();
        $product->forceFill(['stock' => 0])->save();

        try {
            app(ProductStockIntake::class)->submit(
                $product,
                ProductStockIntakeSubmission::structured([
                    [
                        'email' => 'retained@example.test',
                        'password' => 'changed-password',
                        'access_method' => 'none',
                    ],
                ]),
                $admin,
                dispatchNotifications: false,
            );
            $this->fail('Expected registry conflict after stock item deletion.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accounts.0.email', $exception->errors());
            $this->assertStringNotContainsString('retained@example.test', json_encode($exception->errors()));
        }

        $this->assertSame(0, ProductStockItem::query()->count());
        $this->assertSame(1, CredentialIdentity::query()->count());
        $this->assertSame(1, InventoryMovement::query()->where('quantity', 1)->count());
    }

    public function test_same_operation_key_replays_first_result_and_different_payload_fails_closed(): void
    {
        Queue::fake([SendProductStockNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $operationKey = (string) Str::uuid();
        $submission = ProductStockIntakeSubmission::structured([
            [
                'email' => 'idempotent@example.test',
                'password' => 'idempotent-password',
                'access_method' => 'none',
            ],
        ], $operationKey);
        $service = app(ProductStockIntake::class);

        $first = $service->submit($product, $submission, $admin);
        $second = $service->submit($product->fresh(), $submission, $admin);

        $this->assertFalse($first->idempotentReplay);
        $this->assertTrue($second->idempotentReplay);
        $this->assertSame($first->movementId, $second->movementId);
        $this->assertSame(1, ProductStockItem::query()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertSame(1, ProductStockIntakeOperation::query()->count());
        Queue::assertPushedTimes(SendProductStockNotifications::class, 1);

        try {
            $service->submit(
                $product->fresh(),
                ProductStockIntakeSubmission::structured([
                    [
                        'email' => 'different@example.test',
                        'password' => 'different-password',
                        'access_method' => 'none',
                    ],
                ], $operationKey),
                $admin,
            );
            $this->fail('Expected idempotency conflict.');
        } catch (ProductStockIntakeIdempotencyConflict $exception) {
            $this->assertStringNotContainsString('different@example.test', $exception->getMessage());
        }

        $this->assertSame(1, ProductStockItem::query()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_quick_input_replays_same_browser_operation_key_without_duplicate_side_effects(): void
    {
        Queue::fake([SendProductStockNotifications::class]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $operationKey = (string) Str::uuid();
        $payload = [
            'operation_key' => $operationKey,
            'stock_items' => "quick@example.test|password|demo-mfa\nsecond@example.test|password-2",
        ];

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), $payload)
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->actingAs($admin)
            ->post(route('admin.products.stock.store', $product), $payload)
            ->assertRedirect('/admin?tab=produk#product-'.$product->id);

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(2, ProductStockItem::query()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
        $this->assertDatabaseHas('product_stock_intake_operations', [
            'operation_key' => $operationKey,
            'mode' => ProductStockIntakeSubmission::MODE_LEGACY,
            'item_count' => 2,
        ]);
        Queue::assertPushedTimes(SendProductStockNotifications::class, 1);

        $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.stock.store', $product), [
                ...$payload,
                'stock_items' => 'different@example.test|different-password',
            ])
            ->assertRedirect('/admin?tab=produk')
            ->assertSessionHasErrors(['stock_items'], null, 'stockAdjustment');

        $this->assertSame(2, $product->fresh()->stock);
        $this->assertSame(2, ProductStockItem::query()->count());
        $this->assertSame(1, InventoryMovement::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
        Queue::assertPushedTimes(SendProductStockNotifications::class, 1);
    }

    public function test_account_route_allows_owner_and_admin_but_forbids_finance_and_support(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $payload = [
            'operation_key' => (string) Str::uuid(),
            'accounts' => [[
                'email' => 'route-account@example.test',
                'password' => 'route-password',
                'access_method' => 'none',
            ]],
        ];

        foreach ([UserRole::Owner, UserRole::Admin] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson(route('admin.products.stock.accounts.store', $product), $payload)
                ->assertCreated()
                ->assertJsonPath('item_count', 1);
            $product->refresh();
            $product->forceFill(['stock' => 0])->save();
            ProductStockItem::query()->delete();
            CredentialIdentity::query()->delete();
            ProductStockIntakeOperation::query()->delete();
            InventoryMovement::query()->delete();
            AuditLog::query()->delete();
            $payload['operation_key'] = (string) Str::uuid();
        }

        foreach ([UserRole::Finance, UserRole::Support] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->postJson(route('admin.products.stock.accounts.store', $product), [
                    ...$payload,
                    'operation_key' => (string) Str::uuid(),
                ])
                ->assertForbidden();
        }
    }

    public function test_account_http_validation_is_json_only_and_never_flashes_credential_drafts(): void
    {
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $secretDraft = 'never-flash-this-password';

        $response = $this->actingAs($admin)
            ->from('/admin?tab=produk')
            ->post(route('admin.products.stock.accounts.store', $product), [
                'operation_key' => (string) Str::uuid(),
                'accounts' => [[
                    'email' => 'not-valid',
                    'password' => $secretDraft,
                    'access_method' => 'otp_mail_link',
                    'access_value' => 'http://unsafe.example.test/otp',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['accounts.0.email']]);

        $this->assertStringNotContainsString($secretDraft, $response->getContent());
        $this->assertArrayNotHasKey('accounts', session()->getOldInput());
        $this->assertArrayNotHasKey('password', session()->getOldInput());
        $this->assertSame(0, ProductStockItem::query()->count());
        $this->assertSame(0, InventoryMovement::query()->count());
    }
}
