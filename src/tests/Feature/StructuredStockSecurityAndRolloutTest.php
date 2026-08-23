<?php

namespace Tests\Feature;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\StructuredStockInputDisabled;
use App\Jobs\SendProductStockNotifications;
use App\Models\CredentialIdentityBackfillReadiness;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\ProductStockIntake;
use App\Services\ProductStockIntakeSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StructuredStockSecurityAndRolloutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.credentials.identity_fingerprint_key', 'security-test-fingerprint-key');
        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
    }

    public function test_account_input_ignores_legacy_environment_feature_switch(): void
    {
        // Simulate a stale cached value from the removed runtime switch.
        config()->set('services.credentials.structured_stock_input_enabled', false);
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $password = 'legacy-switch-'.Str::random(16);

        $this->assertTrue(app(ProductStockIntake::class)->structuredInputEnabled());

        $this->actingAs($admin)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertSee('Input Akun')
            ->assertSee('id="stock-input-account-panel"', false);

        $response = $this->actingAs($admin)->postJson(
            route('admin.products.stock.accounts.store', $product),
            [
                'operation_key' => (string) Str::uuid(),
                'accounts' => [[
                    'email' => 'legacy-switch@example.test',
                    'password' => $password,
                    'access_method' => CredentialAccessMethod::None->value,
                ]],
            ],
        );

        $response->assertCreated()->assertJsonPath('item_count', 1);
        $this->assertSame(1, ProductStockItem::query()->count());
        $this->assertSame(1, DB::table('product_stock_intake_operations')->count());
        $this->assertStringNotContainsString($password, $response->getContent());
    }

    public function test_account_input_requires_persisted_backfill_readiness_before_accepting_accounts(): void
    {
        $this->resetBackfillReadiness();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $password = 'readiness-disabled-'.Str::random(16);

        try {
            app(ProductStockIntake::class)->submit(
                $product,
                ProductStockIntakeSubmission::structured([[
                    'email' => 'readiness-disabled@example.test',
                    'password' => $password,
                    'access_method' => CredentialAccessMethod::None->value,
                ]]),
                $admin,
                dispatchNotifications: false,
            );
            $this->fail('Structured input was accepted without persisted backfill readiness.');
        } catch (StructuredStockInputDisabled $exception) {
            $this->assertStringNotContainsString($password, $exception->getMessage());
        }

        $this->actingAs($admin)
            ->get('/admin?tab=produk')
            ->assertOk()
            ->assertDontSee('account-email-0', false);
        $this->assertSame(0, ProductStockItem::query()->count());
    }

    public function test_successful_commit_persists_readiness_for_runtime_and_deployment_check(): void
    {
        $this->resetBackfillReadiness();

        $this->artisan('credentials:check-identity-readiness')
            ->assertFailed()
            ->expectsOutputToContain('ready=false');

        $this->artisan('credentials:backfill-identity-registry')
            ->assertSuccessful()
            ->expectsOutputToContain('ready=true');

        $state = CredentialIdentityBackfillReadiness::query()->sole();
        $this->assertSame(CredentialIdentityBackfillReadiness::STATUS_READY, $state->status);
        $this->assertNotNull($state->ready_at);
        $this->assertSame(hash('sha256', 'security-test-fingerprint-key'), $state->fingerprint_key_digest);

        $this->artisan('credentials:check-identity-readiness', ['--json' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('"ready":true');
    }

    public function test_dry_run_does_not_publish_runtime_readiness(): void
    {
        $this->resetBackfillReadiness();
        $product = Product::factory()->create();
        ProductStockItem::create([
            'product_id' => $product->id,
            'secret' => 'dry-readiness@example.test|password|mfa',
        ]);

        $this->artisan('credentials:backfill-identity-registry', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('ready=false');

        $state = CredentialIdentityBackfillReadiness::query()->sole();
        $this->assertSame(CredentialIdentityBackfillReadiness::STATUS_PENDING, $state->status);
        $this->assertSame(0, DB::table('credential_identities')->count());
        $this->assertFalse(app(ProductStockIntake::class)->structuredInputEnabled());
    }

    public function test_duplicate_legacy_emails_do_not_block_readiness(): void
    {
        $this->resetBackfillReadiness();
        $product = Product::factory()->create();
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'failed-readiness@example.test|password-a|mfa-a']);
        ProductStockItem::create(['product_id' => $product->id, 'secret' => 'failed-readiness@example.test|password-b|mfa-b']);

        $this->artisan('credentials:backfill-identity-registry')
            ->assertSuccessful()
            ->expectsOutputToContain('ready=true');

        $state = CredentialIdentityBackfillReadiness::query()->sole();
        $this->assertSame(CredentialIdentityBackfillReadiness::STATUS_READY, $state->status);
        $this->assertSame(0, $state->unresolved_count);
        $this->assertTrue(app(ProductStockIntake::class)->structuredInputEnabled());
    }

    public function test_changing_the_fingerprint_key_invalidates_persisted_readiness(): void
    {
        $this->assertTrue(app(ProductStockIntake::class)->structuredInputEnabled());

        config()->set('services.credentials.identity_fingerprint_key', 'a-different-fingerprint-key');

        $this->assertFalse(app(ProductStockIntake::class)->structuredInputEnabled());
        $this->artisan('credentials:check-identity-readiness')
            ->assertFailed()
            ->expectsOutputToContain('ready=false');
    }

    public function test_backfill_refuses_key_rotation_while_auth_token_registry_rows_exist(): void
    {
        $product = Product::factory()->create();
        ProductStockItem::create([
            'product_id' => $product->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'stable-key@example.test',
                'password',
                CredentialAccessMethod::AuthToken,
                'stable-auth-token',
            )->toArray(),
        ]);

        $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();
        config()->set('services.credentials.identity_fingerprint_key', 'rotated-fingerprint-key');

        $this->artisan('credentials:backfill-identity-registry')
            ->assertFailed()
            ->doesntExpectOutputToContain('stable-key@example.test');

        $this->assertSame(1, DB::table('credential_identities')->count());
        $this->assertFalse(app(ProductStockIntake::class)->structuredInputEnabled());
    }

    public function test_account_input_is_available_without_fingerprint_environment_variable(): void
    {
        $keyPath = storage_path('framework/testing/credential-identity-'.Str::uuid().'.key');
        File::delete($keyPath);
        config()->set('services.credentials.identity_fingerprint_key', null);
        config()->set('services.credentials.identity_fingerprint_key_path', $keyPath);
        $this->resetBackfillReadiness();

        try {
            $this->artisan('credentials:backfill-identity-registry')->assertSuccessful();

            $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
            $admin = User::factory()->create(['role' => UserRole::Admin]);
            $this->actingAs($admin)
                ->get('/admin?tab=produk')
                ->assertOk()
                ->assertSee('Input Akun')
                ->assertSee('id="stock-input-account-panel"', false);
            $response = $this->actingAs($admin)->postJson(
                route('admin.products.stock.accounts.store', $product),
                [
                    'operation_key' => (string) Str::uuid(),
                    'accounts' => [[
                        'email' => 'without-env@example.test',
                        'password' => 'without-env-password',
                        'access_method' => CredentialAccessMethod::AuthToken->value,
                        'access_value' => 'without-env-access-token',
                    ]],
                ],
            );

            $response
                ->assertCreated()
                ->assertJsonPath('item_count', 1);
            $this->assertTrue(File::exists($keyPath));
            $this->assertSame('without-env@example.test', ProductStockItem::query()->sole()->credential_payload['email']);
            $this->assertSame('without-env-access-token', ProductStockItem::query()->sole()->credential_payload['access_value']);
        } finally {
            File::delete($keyPath);
        }
    }

    public function test_unsafe_otp_link_is_rejected_without_server_fetch_or_persistence(): void
    {
        Http::fake();
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);

        try {
            app(ProductStockIntake::class)->submit(
                $product,
                ProductStockIntakeSubmission::structured([[
                    'email' => 'unsafe-link@example.test',
                    'password' => 'unsafe-link-password',
                    'access_method' => CredentialAccessMethod::OtpMailLink->value,
                    'access_value' => 'https://user:pass@example.test/otp',
                ]]),
                User::factory()->create(['role' => UserRole::Admin]),
                dispatchNotifications: false,
            );
            $this->fail('Unsafe OTP link was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('accounts.0.access_value', $exception->errors());
        }

        Http::assertNothingSent();
        $this->assertSame(0, ProductStockItem::query()->count());
        $this->assertSame(0, DB::table('credential_identities')->count());
    }

    public function test_invalid_persisted_stock_format_is_read_only_repair_without_secret_projection(): void
    {
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $canary = 'invalid-format-'.Str::random(16);

        DB::table('product_stock_items')->insert([
            'product_id' => $product->id,
            'credential_format' => 'unknown_format',
            'credential_payload' => Crypt::encryptString(json_encode(['version' => 99], JSON_THROW_ON_ERROR)),
            'secret' => Crypt::encryptString($canary),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)->get('/admin?tab=produk')->assertOk();

        $response->assertSee('REPAIR');
        $this->assertStringNotContainsString($canary, $response->getContent());
    }

    public function test_canary_credentials_stay_out_of_operational_persistence_and_delivery_values_remain_memory_only(): void
    {
        Queue::fake([SendProductStockNotifications::class]);
        $suffix = Str::lower(Str::random(18));
        $email = 'canary-'.$suffix.'@example.test';
        $password = 'password-'.$suffix;
        $token = 'token-'.$suffix;
        $otpUrl = 'https://mail.example.test/otp?opaque='.$suffix;
        $product = Product::factory()->create(['stock' => 0, 'reserved_stock' => 0]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        app(ProductStockIntake::class)->submit(
            $product,
            ProductStockIntakeSubmission::structured([
                [
                    'email' => $email,
                    'password' => $password,
                    'access_method' => CredentialAccessMethod::AuthToken->value,
                    'access_value' => $token,
                ],
                [
                    'email' => 'otp-'.$suffix.'@example.test',
                    'password' => 'otp-password-'.$suffix,
                    'access_method' => CredentialAccessMethod::OtpMailLink->value,
                    'access_value' => $otpUrl,
                ],
            ], null),
            $admin,
            dispatchNotifications: false,
        );

        $customer = Customer::factory()->create();
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        ProductStockItem::query()->update(['order_item_id' => $item->id]);

        $delivery = app(CredentialDeliveryPresentation::class)->forOrder($order->fresh());
        $accounts = $delivery->groups()[0]->accounts();
        $this->assertCount(2, $accounts);
        $this->assertTrue($accounts[0]->email() === $email);
        $this->assertTrue($accounts[0]->password() === $password);
        $this->assertSame(CredentialAccessMethod::AuthToken, $accounts[0]->accessMethod());
        $this->assertTrue($accounts[0]->accessValue() === $token);
        $this->assertTrue($accounts[1]->accessValue() === $otpUrl);
        $this->assertFalse(str_contains(json_encode($delivery, JSON_THROW_ON_ERROR), $password));
        $this->assertFalse(str_contains(serialize($delivery->__debugInfo()), $token));

        $operationalData = '';
        foreach (Schema::getTableListing() as $table) {
            foreach (DB::table($table)->get() as $row) {
                $operationalData .= json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        foreach ([$email, $password, $token, $otpUrl] as $canary) {
            $this->assertFalse(str_contains($operationalData, $canary));
        }

        foreach (glob(storage_path('logs/*')) ?: [] as $logPath) {
            if (is_file($logPath)) {
                $contents = File::get($logPath);
                $this->assertFalse(str_contains($contents, $email));
                $this->assertFalse(str_contains($contents, $password));
                $this->assertFalse(str_contains($contents, $token));
                $this->assertFalse(str_contains($contents, $otpUrl));
            }
        }
    }

    public function test_legacy_duplicate_email_backfill_reports_no_identity_conflict(): void
    {
        $product = Product::factory()->create();
        $suffix = $this->suffix();
        $first = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'backfill-'.$suffix.'@example.test|password-a|mfa-a']);
        $second = ProductStockItem::create(['product_id' => $product->id, 'secret' => 'backfill-'.$suffix.'@example.test|password-b|mfa-b']);

        $command = $this->artisan('credentials:backfill-identity-registry', ['--dry-run' => true]);

        $command->assertExitCode(0);
        $this->assertSame(0, DB::table('credential_identities')->count());
        $this->assertNotSame($first->id, $second->id);
    }

    private function suffix(): string
    {
        return Str::lower(Str::random(12));
    }

    private function resetBackfillReadiness(): void
    {
        CredentialIdentityBackfillReadiness::query()->update([
            'status' => CredentialIdentityBackfillReadiness::STATUS_PENDING,
            'fingerprint_key_digest' => null,
            'scan_upper_bound_id' => null,
            'last_processed_id' => 0,
            'scanned_count' => 0,
            'registered_count' => 0,
            'already_registered_count' => 0,
            'skipped_unrecognized_count' => 0,
            'conflict_count' => 0,
            'unresolved_count' => 0,
            'started_at' => null,
            'completed_at' => null,
            'ready_at' => null,
        ]);
    }
}
