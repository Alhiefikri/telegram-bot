<?php

namespace Tests\Feature;

use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\User;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\NonTransactionalDatabaseTestCase;

final class UniqueCredentialEmailSecurityTest extends NonTransactionalDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config([
            'auth.admin_allowed_logins' => '',
            'services.telegram.token' => 'fixture-token',
            'services.telegram.admin_telegram_chat_id' => '999123',
        ]);
    }

    public static function unauthorizedEndpointActors(): array
    {
        return [
            'guest' => ['guest', null, 302],
            'customer guard' => ['customer', null, 302],
            'Support' => ['web', UserRole::Support, 403],
            'Finance' => ['web', UserRole::Finance, 403],
            'unassigned staff role' => ['web', null, 403],
        ];
    }

    #[DataProvider('unauthorizedEndpointActors')]
    public function test_admin_boundary_denies_history_and_mutation_without_leaking_credentials(string $guard, ?UserRole $role, int $status): void
    {
        $fixture = $this->reviewFixture();
        $before = $this->snapshot($fixture);
        if ($guard === 'customer') {
            $this->actingAs($fixture['customer'], 'customer');
            app('auth')->shouldUse('web');
        } elseif ($guard === 'web') {
            $this->actingAs(User::factory()->create(['role' => $role]));
        }

        foreach (['admin.manual-fulfillment-reviews.index', 'admin.manual-fulfillment-reviews.show'] as $route) {
            $response = $this->get(route($route, $route === 'admin.manual-fulfillment-reviews.show' ? $fixture['review'] : []));
            $response->assertStatus($status)
                ->assertDontSee($fixture['history']->invoice_number)
                ->assertDontSee('historical@example.test')
                ->assertDontSee('historical-password');
            if ($status === 302) {
                $response->assertRedirect(route('admin.login'));
            }
        }
        $response = $this->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $this->payload($fixture));
        $response->assertStatus($status)->assertDontSee('submitted-password');
        if ($status === 302) {
            $response->assertRedirect(route('admin.login'));
        }

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertNoCredentialFlash(['submitted-password', 'fresh@example.test']);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public static function unauthorizedServiceRoles(): array
    {
        return ['Support' => [UserRole::Support], 'Finance' => [UserRole::Finance], 'no role' => [null]];
    }

    #[DataProvider('unauthorizedServiceRoles')]
    public function test_service_enforces_mutation_permission_independently_of_http_middleware(?UserRole $role): void
    {
        $fixture = $this->reviewFixture();
        $before = $this->snapshot($fixture);
        $actor = User::factory()->create(['role' => $role]);

        try {
            app(ManualFulfillmentResolutionService::class)->provideLocalCredentials(
                $fixture['review'], $actor, [$fixture['item']->id => ['fresh@example.test|submitted-password']], 'Inspected missing unit.',
            );
            $this->fail('The service accepted an actor without resolution permission.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame($before, $this->snapshot($fixture));
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_owner_can_read_email_invoice_history_but_not_historical_passwords_or_unassigned_secrets(): void
    {
        $fixture = $this->reviewFixture();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $other = $this->reviewFixture();
        $other['history']->orderItems()->sole()->update(['product_id' => $fixture['product']->id]);
        $other['historyStock']->update(['product_id' => $fixture['product']->id, 'secret' => 'other-customer@example.test|other-customer-password']);

        $this->actingAs($owner)->get(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertOk()
            ->assertSee('historical@example.test')
            ->assertSee($fixture['history']->invoice_number)
            ->assertDontSee('historical-password')
            ->assertDontSee('unassigned-password')
            ->assertDontSee('other-customer@example.test')
            ->assertDontSee('other-customer-password');
    }

    public function test_owner_completion_stores_encrypted_order_owned_credentials_without_flashing_or_auditing_them(): void
    {
        $fixture = $this->reviewFixture();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $account = [
            'email' => 'fresh@example.test',
            'password' => 'submitted-password',
            'access_method' => 'otp_mail_link',
            'access_value' => 'https://mail.example.test/private-access-token',
        ];
        $payload = $this->payload($fixture, json_encode([$account], JSON_THROW_ON_ERROR), 'structured_account');

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $payload)
            ->assertRedirect(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('_old_input');

        $stock = $fixture['item']->stockItems()->sole();
        $this->assertSame(CredentialFormat::StructuredAccount, $stock->credential_format);
        $this->assertSame('submitted-password', $stock->credential_payload['password']);
        $this->assertSame($account['access_value'], $stock->credential_payload['access_value']);
        $this->assertSame($fixture['product']->id, $stock->product_id);
        $this->assertNull($stock->supplier_purchase_id);
        $this->assertSame(OrderStatus::Completed, $fixture['order']->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $fixture['payment']->fresh()->status);
        $this->assertSame('fulfilled', $fixture['review']->fresh()->state);
        $this->assertSame($owner->id, $fixture['review']->fresh()->actor_user_id);
        $this->assertSame(1, $fixture['product']->fresh()->stock);
        $this->assertNull($fixture['available']->fresh()->order_item_id);
        $raw = DB::table('product_stock_items')->where('id', $stock->id)->first();
        $this->assertStringNotContainsString('submitted-password', (string) $raw->credential_payload);
        $this->assertStringNotContainsString('private-access-token', (string) $raw->credential_payload);
        $audit = AuditLog::query()->where('subject_type', ManualFulfillmentReview::class)
            ->where('subject_id', (string) $fixture['review']->id)
            ->where('action', 'manual_fulfillment_review.local_credentials_provided')->sole();
        $this->assertSame(1, $audit->new_values['units']);
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertNoCredentialFlash(['fresh@example.test', 'submitted-password', 'private-access-token']);
        $this->assertAuditExcludes(['fresh@example.test', 'submitted-password', 'private-access-token', 'historical-password', 'unassigned-password']);
        Queue::assertPushed(SendTelegramReceipt::class, 1);
    }

    public function test_audit_persistence_failure_rolls_back_allocated_accounts_and_order_completion(): void
    {
        $fixture = $this->reviewFixture();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $before = $this->snapshot($fixture);
        $this->mock(AuditLogger::class)->shouldReceive('log')->once()
            ->andReturnUsing(function (string $action) use ($fixture): never {
                $this->assertSame('manual_fulfillment_review.local_credentials_provided', $action);
                $this->assertSame(1, $fixture['item']->stockItems()->count());
                $this->assertSame(OrderStatus::Completed, $fixture['order']->fresh()->order_status);
                $this->assertSame('fulfilled', $fixture['review']->fresh()->state);

                throw new RuntimeException('Audit persistence unavailable.');
            });

        try {
            app(ManualFulfillmentResolutionService::class)->provideLocalCredentials(
                $fixture['review'], $owner, [$fixture['item']->id => ['fresh@example.test|submitted-password']], 'Verified missing unit.',
            );
            $this->fail('The resolution succeeded without its audit record.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Audit persistence unavailable.', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertAuditExcludes(['submitted-password']);
    }

    public static function foreignItemAttempts(): array
    {
        return ['foreign item replaces target' => [false], 'foreign item appended to target' => [true]];
    }

    #[DataProvider('foreignItemAttempts')]
    public function test_foreign_order_item_injection_is_rejected_without_changing_either_order(bool $includeTarget): void
    {
        $fixture = $this->reviewFixture();
        $foreign = $this->reviewFixture();
        $before = $this->snapshot($fixture);
        $foreignBefore = $this->snapshot($foreign);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $payload = $this->payload($foreign);
        if ($includeTarget) {
            $payload['items'] += $this->payload($fixture)['items'];
        }

        $this->actingAs($owner)->from(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $payload)
            ->assertRedirect(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertSessionHasErrors('credentials')->assertSessionMissing('_old_input');

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertSame($foreignBefore, $this->snapshot($foreign));
        $this->assertNoCredentialFlash(['fresh@example.test', 'submitted-password']);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_untrusted_identity_and_payment_fields_cannot_reassign_the_order_or_credential(): void
    {
        $fixture = $this->reviewFixture();
        $foreign = $this->reviewFixture();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $account = [
            'email' => 'fresh@example.test', 'password' => 'submitted-password', 'access_method' => 'none',
            'customer_id' => $foreign['customer']->id, 'product_id' => $foreign['product']->id,
            'order_id' => $foreign['order']->id, 'order_item_id' => $foreign['item']->id,
            'payment_id' => $foreign['payment']->id, 'supplier_purchase_id' => 999999,
            'unique_credential_email' => false,
        ];
        $payload = $this->payload($fixture, json_encode([$account], JSON_THROW_ON_ERROR), 'structured_account');
        $payload += [
            'customer_id' => $foreign['customer']->id, 'product_id' => $foreign['product']->id,
            'order_id' => $foreign['order']->id, 'payment_id' => $foreign['payment']->id,
            'payment_status' => 'refunded', 'order_status' => 'cancelled', 'unique_credential_email' => false,
        ];
        $payload['items'][$fixture['item']->id]['product_id'] = $foreign['product']->id;

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $stock = $fixture['item']->stockItems()->sole();
        $this->assertSame($fixture['product']->id, $stock->product_id);
        $this->assertNull($stock->supplier_purchase_id);
        $this->assertArrayNotHasKey('product_id', $stock->credential_payload);
        $this->assertArrayNotHasKey('customer_id', $stock->credential_payload);
        $this->assertArrayNotHasKey('payment_id', $stock->credential_payload);
        $this->assertSame($fixture['customer']->id, $fixture['order']->fresh()->customer_id);
        $this->assertSame($fixture['payment']->id, $fixture['review']->fresh()->payment_id);
        $this->assertSame($fixture['order']->id, $fixture['payment']->fresh()->order_id);
        $this->assertSame(PaymentStatus::Paid, $fixture['order']->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $fixture['payment']->fresh()->status);
        $this->assertSame(OrderStatus::Completed, $fixture['order']->fresh()->order_status);
        $this->assertTrue($fixture['item']->fresh()->unique_credential_email);
        $this->assertSame($foreign['customer']->id, $foreign['order']->fresh()->customer_id);
        $this->assertSame(OrderStatus::Processing, $foreign['order']->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $foreign['payment']->fresh()->status);
        $this->assertSame($foreign['order']->id, $foreign['payment']->fresh()->order_id);
        $this->assertSame($foreign['product']->id, $foreign['item']->fresh()->product_id);
        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $foreign['review']->fresh()->state);
        $this->assertSame(0, $foreign['item']->stockItems()->count());
        $this->assertNoCredentialFlash(['submitted-password']);
        $this->assertAuditExcludes(['submitted-password']);
    }

    public static function invalidCredentialPayloads(): array
    {
        $valid = ['email' => 'fresh@example.test', 'password' => 'submitted-password', 'access_method' => 'none'];

        return [
            'malformed JSON' => ['structured_account', '[{"password":"submitted-password"}', 1],
            'JSON object instead of list' => ['structured_account', json_encode($valid), 1],
            'JSON scalar list member' => ['structured_account', '["submitted-password"]', 1],
            'empty structured list' => ['structured_account', '[]', 1],
            'missing structured password after valid account' => ['structured_account', json_encode([$valid, ['email' => 'second@example.test']]), 2],
            'invalid email after valid account' => ['structured_account', json_encode([$valid, array_replace($valid, ['email' => 'invalid-email'])]), 2],
            'unsupported payload version' => ['structured_account', json_encode([array_replace($valid, ['version' => 2])]), 1],
            'access value forbidden for none' => ['structured_account', json_encode([array_replace($valid, ['access_value' => 'submitted-private-token'])]), 1],
            'invalid OTP scheme' => ['structured_account', json_encode([array_replace($valid, ['access_method' => 'otp_mail_link', 'access_value' => 'javascript:submitted-private-token'])]), 1],
            'account tutorial override' => ['structured_account', json_encode([array_replace($valid, ['tutorial_override' => 'submitted-private-token'])]), 1],
            'excess structured accounts' => ['structured_account', json_encode([$valid, array_replace($valid, ['email' => 'second@example.test'])]), 1],
            'too few legacy accounts' => ['legacy', 'fresh@example.test|submitted-password', 2],
            'excess legacy accounts' => ['legacy', "fresh@example.test|submitted-password\nsecond@example.test|second-password", 1],
            'blank legacy lines' => ['legacy', " \n\r\n ", 1],
            'oversized individual legacy account' => ['legacy', 'fresh@example.test|'.str_repeat('x', 16384), 1],
            'unsupported format' => ['unknown', 'fresh@example.test|submitted-password', 1],
        ];
    }

    #[DataProvider('invalidCredentialPayloads')]
    public function test_invalid_batches_fail_atomically_without_credential_flash(string $format, string $credentials, int $quantity): void
    {
        $fixture = $this->reviewFixture($quantity);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $before = $this->snapshot($fixture);
        $payload = $this->payload($fixture, $credentials, $format);

        $this->actingAs($owner)->from(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $payload)
            ->assertRedirect(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertSessionHasErrors()->assertSessionMissing('_old_input');

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertNoCredentialFlash(['submitted-password', 'second-password', 'submitted-private-token']);
        $this->assertAuditExcludes(['submitted-password', 'second-password', 'submitted-private-token']);
        $this->get(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertOk()->assertDontSee('submitted-password')->assertDontSee('submitted-private-token');
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public static function missingRequestFields(): array
    {
        return ['missing all items' => ['items'], 'missing format' => ['format'], 'missing credentials' => ['credentials']];
    }

    #[DataProvider('missingRequestFields')]
    public function test_request_validation_does_not_flash_credentials_when_required_fields_are_missing(string $missing): void
    {
        $fixture = $this->reviewFixture();
        $before = $this->snapshot($fixture);
        $payload = $this->payload($fixture);
        if (in_array($missing, ['format', 'credentials'], true)) {
            unset($payload['items'][$fixture['item']->id][$missing]);
            $error = 'items.'.$fixture['item']->id.'.'.$missing;
        } else {
            unset($payload[$missing]);
            $error = $missing;
        }
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->from(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $payload)
            ->assertRedirect()->assertSessionHasErrors($error)->assertSessionMissing('_old_input');

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertNoCredentialFlash(['fresh@example.test', 'submitted-password']);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public static function duplicateHistoryFormats(): array
    {
        return ['legacy' => ['legacy'], 'structured' => ['structured_account']];
    }

    #[DataProvider('duplicateHistoryFormats')]
    public function test_historical_case_and_password_change_is_rejected_with_invoice_not_secret_even_after_product_toggle(string $format): void
    {
        $fixture = $this->reviewFixture(2);
        $fixture['product']->update(['unique_credential_email' => false]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $before = $this->snapshot($fixture);
        $credentials = $format === 'legacy'
            ? "fresh@example.test|submitted-password\n HISTORICAL@EXAMPLE.TEST |changed-password"
            : json_encode([
                ['email' => 'fresh@example.test', 'password' => 'submitted-password', 'access_method' => 'none'],
                ['email' => ' HISTORICAL@EXAMPLE.TEST ', 'password' => 'changed-password', 'access_method' => 'none'],
            ], JSON_THROW_ON_ERROR);

        $response = $this->actingAs($owner)->from(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $this->payload($fixture, $credentials, $format));
        $response->assertRedirect()->assertSessionHasErrors('credentials')->assertSessionMissing('_old_input');
        $errors = implode(' ', session('errors')->getBag('default')->all());
        $this->assertStringContainsString($fixture['history']->invoice_number, $errors);
        $this->assertStringContainsString('historical@example.test', $errors);
        foreach (['historical-password', 'changed-password', 'submitted-password', 'unassigned-password'] as $password) {
            $this->assertStringNotContainsString($password, $errors);
        }
        $this->assertTrue($fixture['item']->fresh()->unique_credential_email);
        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertNoCredentialFlash(['changed-password', 'submitted-password', 'historical-password']);
        $this->assertAuditExcludes(['changed-password', 'submitted-password', 'historical-password']);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public static function inactiveReviewStates(): array
    {
        return [
            'unpaid order and payment' => ['unpaid'],
            'order unpaid but payment paid' => ['order_unpaid'],
            'payment unpaid but order paid' => ['payment_unpaid'],
            'refunded order and payment' => ['refunded'],
            'refunded payment but order paid' => ['payment_refunded'],
            'cancelled order with paid payment' => ['cancelled'],
            'expired order with paid payment' => ['expired'],
            'completed order with pending review' => ['completed'],
            'refunded review' => ['review_refunded'],
            'rejected review' => ['review_rejected'],
            'uncertain refund review' => ['review_refund_uncertain'],
            'refund record while paid' => ['refund_record'],
            'unrelated review reason' => ['other_reason'],
        ];
    }

    #[DataProvider('inactiveReviewStates')]
    public function test_only_active_paid_processing_unique_email_reviews_accept_credentials(string $state): void
    {
        $fixture = $this->reviewFixture();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        if (in_array($state, ['unpaid', 'order_unpaid'], true)) {
            $fixture['order']->update(['payment_status' => PaymentStatus::Pending]);
        }
        if (in_array($state, ['unpaid', 'payment_unpaid'], true)) {
            $fixture['payment']->update(['status' => PaymentStatus::Pending, 'paid_at' => null]);
        }
        if ($state === 'refunded') {
            $fixture['order']->update(['payment_status' => PaymentStatus::Refunded]);
        }
        if (in_array($state, ['refunded', 'payment_refunded'], true)) {
            $fixture['payment']->update(['status' => PaymentStatus::Refunded]);
        }
        if (in_array($state, ['cancelled', 'expired', 'completed'], true)) {
            $fixture['order']->update(['order_status' => OrderStatus::from($state)]);
        }
        if (str_starts_with($state, 'review_')) {
            $fixture['review']->update(['state' => substr($state, strlen('review_'))]);
        }
        if ($state === 'refund_record') {
            OrderRefund::create([
                'order_id' => $fixture['order']->id, 'payment_id' => $fixture['payment']->id,
                'customer_id' => $fixture['customer']->id, 'amount' => 5000, 'currency' => 'IDR', 'state' => 'requested',
            ]);
        }
        if ($state === 'other_reason') {
            $fixture['review']->update(['reason' => ManualFulfillmentReview::REASON_LATE_PAYMENT]);
        }
        $before = $this->snapshot($fixture);

        $this->actingAs($owner)->from(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $this->payload($fixture))
            ->assertRedirect()->assertSessionHasErrors('credentials')->assertSessionMissing('_old_input');

        $this->assertSame($before, $this->snapshot($fixture));
        $this->assertNoCredentialFlash(['submitted-password']);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_manual_unlimited_credentials_can_be_submitted_without_a_resolution_note(): void
    {
        $fixture = $this->reviewFixture();
        $fixture['review']->update(['reason' => ManualFulfillmentReview::REASON_MANUAL_UNLIMITED]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $payload = $this->payload($fixture);
        unset($payload['reason']);

        $this->actingAs($owner)->get(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertOk()->assertDontSee('Catatan resolusi')->assertDontSee('Alasan resolusi');
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.credentials', $fixture['review']), $payload)
            ->assertRedirect(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertSessionHasNoErrors();

        $review = $fixture['review']->fresh();
        $this->assertSame(ManualFulfillmentReview::STATE_FULFILLED, $review->state);
        $this->assertNull($review->resolution_reason);
        $this->assertSame($owner->id, $review->actor_user_id);
        $this->assertNotNull($review->resolved_at);
        $audit = AuditLog::where('action', 'manual_fulfillment_review.local_credentials_provided')
            ->where('subject_id', (string) $review->id)->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertNotNull($audit->created_at);
        $this->get(route('admin.manual-fulfillment-reviews.show', $review))
            ->assertOk()->assertDontSee('Alasan resolusi')->assertSee($owner->name);
    }

    public function test_refund_still_requires_a_resolution_reason_and_old_reasons_remain_visible(): void
    {
        $fixture = $this->reviewFixture();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('admin.manual-fulfillment-reviews.resolve', $fixture['review']), [
                'action' => 'refund',
                'refund_provider_reference' => 'refund-reference',
                'refund_provider_result' => 'success',
            ])->assertSessionHasErrors('reason');
        $this->assertSame(ManualFulfillmentReview::STATE_PENDING, $fixture['review']->fresh()->state);
        $fixture['review']->update(['resolution_reason' => 'Catatan historis']);
        $this->get(route('admin.manual-fulfillment-reviews.show', $fixture['review']))
            ->assertOk()->assertSee('Alasan resolusi')->assertSee('Catatan historis');
    }

    public function test_buyer_reads_hide_admin_history_hold_reason_and_foreign_credentials_before_and_after_completion(): void
    {
        $fixture = $this->reviewFixture(2);
        $foreign = $this->reviewFixture();
        $foreign['historyStock']->update(['secret' => 'foreign-buyer@example.test|foreign-buyer-password']);
        ProductStockItem::create([
            'product_id' => $fixture['product']->id, 'order_item_id' => $fixture['item']->id,
            'secret' => 'partial@example.test|partial-password',
        ]);
        $fixture['item']->update(['deferred_local_quantity' => 1]);
        $fixture['review']->update(['resolution_reason' => 'INTERNAL-OPERATOR-HISTORY-MARKER']);

        $this->actingAs($fixture['customer'], 'customer');
        foreach (['customer.orders.index', 'customer.orders.show'] as $route) {
            $response = $this->get(route($route, $route === 'customer.orders.show' ? $fixture['order'] : []));
            $response->assertOk()->assertSee($fixture['order']->invoice_number);
            $this->assertBuyerResponseIsPrivate($response, $fixture, $foreign);
            $response->assertDontSee('partial-password');
        }
        $response = $this->get(route('customer.orders.show', $foreign['history']));
        $response->assertNotFound()->assertDontSee('foreign-buyer@example.test')->assertDontSee('foreign-buyer-password');

        $owner = User::factory()->create(['role' => UserRole::Owner]);
        app(ManualFulfillmentResolutionService::class)->provideLocalCredentials(
            $fixture['review'], $owner, [$fixture['item']->id => ['fresh@example.test|submitted-password']], 'Verified remaining local unit.',
        );

        $response = $this->actingAs($fixture['customer'], 'customer')->get(route('customer.orders.show', $fixture['order']));
        $response->assertOk()->assertSee('partial@example.test')->assertSee('fresh@example.test');
        $this->assertBuyerResponseIsPrivate($response, $fixture, $foreign);
        $this->actingAs($foreign['customer'], 'customer')->get(route('customer.orders.show', $fixture['order']))
            ->assertNotFound()->assertDontSee('fresh@example.test')->assertDontSee('submitted-password')->assertDontSee('partial-password');
    }

    private function assertBuyerResponseIsPrivate(TestResponse $response, array $fixture, array $foreign): void
    {
        foreach ([
            'unique_email_unavailable', 'unique_credential_email', 'deferred_local_quantity',
            'Riwayat email akun pelanggan', 'INTERNAL-OPERATOR-HISTORY-MARKER',
            'historical@example.test', 'historical-password', 'unassigned-password',
            $foreign['history']->invoice_number, 'foreign-buyer@example.test', 'foreign-buyer-password',
            route('admin.manual-fulfillment-reviews.show', $fixture['review']),
        ] as $private) {
            $response->assertDontSee($private);
        }
    }

    private function assertNoCredentialFlash(array $secrets): void
    {
        $this->assertFalse(session()->has('_old_input'));
        $session = json_encode(session()->all(), JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $session);
        }
    }

    private function assertAuditExcludes(array $secrets): void
    {
        $audit = json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $audit);
        }
    }

    private function snapshot(array $fixture): array
    {
        return [
            'order' => $fixture['order']->fresh()->getAttributes(),
            'payment' => $fixture['payment']->fresh()->getAttributes(),
            'review' => $fixture['review']->fresh()->getAttributes(),
            'item' => $fixture['item']->fresh()->getAttributes(),
            'product' => $fixture['product']->fresh()->getAttributes(),
            'stock' => ProductStockItem::query()->orderBy('id')->get()->map(fn (ProductStockItem $stock): array => $stock->getAttributes())->all(),
            'audit_count' => AuditLog::query()->count(),
            'refund_count' => OrderRefund::query()->count(),
        ];
    }

    private function payload(array $fixture, string $credentials = 'fresh@example.test|submitted-password', string $format = 'legacy'): array
    {
        return [
            'reason' => 'Verified missing local account.',
            'items' => [$fixture['item']->id => ['format' => $format, 'credentials' => $credentials]],
        ];
    }

    private function reviewFixture(int $quantity = 1): array
    {
        $customer = Customer::factory()->create(['email_verified_at' => now(), 'locale' => 'id']);
        $product = Product::factory()->create([
            'unique_credential_email' => true, 'price_amount' => 5000, 'stock' => 1, 'reserved_stock' => 0,
        ]);
        $history = Order::factory()->create([
            'customer_id' => $customer->id, 'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid, 'total_amount' => 5000,
        ]);
        $historyItem = OrderItem::factory()->create([
            'order_id' => $history->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'unique_credential_email' => true, 'quantity' => 1, 'local_quantity' => 1,
            'supplier_quantity' => 0, 'unit_price' => 5000, 'subtotal' => 5000,
        ]);
        $historyStock = ProductStockItem::create([
            'product_id' => $product->id, 'order_item_id' => $historyItem->id,
            'secret' => 'historical@example.test|historical-password',
        ]);
        $available = ProductStockItem::create([
            'product_id' => $product->id, 'secret' => 'HISTORICAL@example.test|unassigned-password',
        ]);
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'order_status' => OrderStatus::Processing,
            'payment_status' => PaymentStatus::Paid, 'total_amount' => 5000 * $quantity,
        ]);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => $quantity, 'local_quantity' => $quantity, 'supplier_quantity' => 0,
            'deferred_local_quantity' => $quantity, 'unique_credential_email' => true,
            'unit_price' => 5000, 'subtotal' => 5000 * $quantity,
        ]);
        $payment = Payment::factory()->create([
            'order_id' => $order->id, 'provider' => 'manual', 'method' => 'qris',
            'status' => PaymentStatus::Paid, 'paid_at' => now(), 'amount' => 5000 * $quantity,
        ]);
        $review = ManualFulfillmentReview::create([
            'order_id' => $order->id, 'payment_id' => $payment->id, 'amount' => 5000 * $quantity,
            'currency' => 'IDR', 'reason' => ManualFulfillmentReview::REASON_UNIQUE_EMAIL_UNAVAILABLE,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);

        return compact('customer', 'product', 'history', 'historyStock', 'available', 'order', 'item', 'payment', 'review');
    }
}
