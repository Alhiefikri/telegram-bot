<?php

namespace Tests\Feature\Telegram;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Exceptions\CheckoutPaymentUnavailable;
use App\Exceptions\InvalidOrderTransition;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualFulfillmentReview;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\Admin\AdminOrderOperations;
use App\Services\Admin\ManualFulfillmentResolutionService;
use App\Services\CartService;
use App\Services\Checkout\CheckoutManualAlternative;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Telegram\Callbacks\TelegramAdminPaymentCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCheckoutCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCheckoutManualAlternativeCallbackHandler;
use App\Services\Telegram\CheckoutInstructionDeliveryPipeline;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Services\Telegram\TelegramManualPaymentIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\WijayaPay\WijayaPayCallbackProcessor;
use App\Services\WijayaPay\WijayaPayProviderAdapter;
use App\Services\WijayaPay\WijayaPayProviderException;
use App\Services\WijayaPay\WijayaPayReconciliationQuery;
use App\Services\WijayaPay\WijayaPayStatus;
use App\Services\WijayaPay\WijayaPayStatusResponse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class CheckoutManualAlternativeTest extends TelegramBotTestSupport
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Cache::flush();
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'wijayapay', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
        config()->set('services.wijayapay', array_replace(config('services.wijayapay', []), [
            'base_url' => 'https://gateway.wijayapay.com', 'code_merchant' => 'WP692f1c7bafd86', 'api_key' => 'secret',
            'callback_url' => 'https://store.example.test/api/webhook/wijayapay',
            'return_url' => 'https://store.example.test/shop/orders',
            'allowed_hosts' => ['gateway.wijayapay.com', 'wijayapay.com'],
            'callback_allowed_hosts' => ['store.example.test'], 'return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => ['currency' => 'IDR', 'currency_confirmed' => true, 'max_fee_bps' => 500, 'expiry_confirmed' => true],
        ]));
    }

    public function test_pending_provider_is_rechecked_and_switch_preserves_order_pricing_reservation_and_provenance(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $this->providerStatus($payment, WijayaPayStatus::Pending);
        $snapshot = $payment->only(['provider', 'merchant_ref', 'provider_reference', 'provider_payload', 'provider_presentation', 'provider_total_payment']);
        $retired = 0;
        $result = app(CheckoutManualAlternative::class)->switch($customer, $payment, function () use (&$retired): void {
            $retired++;
        });

        $this->assertSame('switched', $result['status']);
        $this->assertSame(1, $retired);
        $this->assertSame($order->id, $result['order']->id);
        $this->assertSame($order->expires_at->timestamp, $result['order']->expires_at->timestamp);
        $this->assertSame($order->total_amount, $result['payment']->amount);
        $this->assertSame($payment->id, $result['payment']->manual_alternative_for_payment_id);
        $this->assertSame($snapshot, $payment->fresh()->only(array_keys($snapshot)));
        $this->assertNotNull($payment->fresh()->manual_alternative_at);
        $this->assertSame('manual', $order->fresh('payments')->payments->first()->provider);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 2);
        $this->assertSame(0, $this->sales($order));
        $this->assertNotNull(app(TelegramOutboundEffectService::class)->forOrder($order->id, TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT));
    }

    public function test_definite_create_failure_can_switch_without_recreating_provider_invoice_and_replay_is_idempotent(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        Http::preventStrayRequests();
        $result = $this->switch($customer, $payment);
        $again = $this->switch($customer, $payment->fresh());

        $this->assertSame('switched', $result['status']);
        $this->assertSame('switched', $again['status']);
        $this->assertArrayNotHasKey('payment', $again);
        $this->assertSame(2, $order->payments()->count());
        Http::assertNothingSent();
    }

    public function test_unavailable_provider_status_blocks_switch_instead_of_trusting_local_pending(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andThrow(new WijayaPayProviderException('Unavailable', operation: 'transactionStatus', httpStatus: 503, reason: 'http_503'));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);

        $this->assertSame('unknown', $this->switch($customer, $payment)['status']);
        $this->assertSame(1, $order->payments()->count());
        $this->assertNull($payment->fresh()->manual_alternative_at);
    }

    public function test_authoritative_paid_status_settles_automatic_and_never_creates_manual_payment(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $this->providerStatus($payment, WijayaPayStatus::Paid);

        $this->assertSame('paid', $this->switch($customer, $payment)['status']);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
    }

    #[DataProvider('ineligibleCases')]
    public function test_paid_expired_foreign_non_qris_and_invalid_amount_cannot_switch(string $case): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        match ($case) {
            'paid' => $payment->update(['status' => PaymentStatus::Paid]),
            'expired' => $order->update(['expires_at' => now()->subSecond()]),
            'foreign' => $customer = Customer::factory()->create(),
            'non_qris' => $payment->update(['method' => 'VA']),
            'amount' => $payment->update(['amount' => $payment->amount + 1]),
            'currency' => $payment->update(['currency' => 'USD']),
        };
        $this->assertSame('unavailable', $this->switch($customer, $payment->fresh())['status']);
        $this->assertSame(1, $order->payments()->count());
    }

    public static function ineligibleCases(): array
    {
        return array_map(fn (string $case): array => [$case], ['paid', 'expired', 'foreign', 'non_qris', 'amount', 'currency']);
    }

    public function test_qr_retirement_failure_rolls_back_switch(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        try {
            app(CheckoutManualAlternative::class)->switch($customer, $payment, fn () => throw new \RuntimeException('Delete unavailable'));
            $this->fail('A failed deletion must prevent exposing manual QR.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Delete unavailable', $exception->getMessage());
        }
        $this->assertSame(1, $order->payments()->count());
        $this->assertNull($payment->fresh()->manual_alternative_at);
        $this->assertNotNull($payment->fresh()->manual_alternative_retirement_token);
        $this->assertSame('unknown', $this->switch($customer, $payment->fresh())['status']);
        $this->travel(61)->seconds();
        $this->assertSame('switched', $this->switch($customer, $payment->fresh())['status']);
        $this->assertSame(2, $order->payments()->count());
    }

    public function test_proof_and_admin_approval_fulfill_once_and_late_original_payment_remains_reviewable(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $manual = $this->switch($customer, $payment)['payment'];
        app(TelegramManualPaymentIntents::class)->submitManualPaymentProof($customer, '12345', [
            'photo' => [['file_id' => 'proof-photo', 'width' => 800, 'height' => 800]],
        ], $order->invoice_number);
        $this->assertNotNull($manual->fresh()->payment_proof_submitted_at);
        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        app(AdminOrderOperations::class)->completeManualOrder($order->fresh());
        app(AdminOrderOperations::class)->completeManualOrder($order->fresh());
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());

        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $manual->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(0, $product->fresh()->reserved_stock);
        $this->assertSame(1, $this->sales($order));
        $this->assertSame(1, ManualFulfillmentReview::query()->where('payment_id', $payment->id)->where('reason', ManualFulfillmentReview::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE)->count());
    }

    public function test_provider_money_before_manual_approval_does_not_release_reservations_or_approve_manual_proof(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $manual = $this->switch($customer, $payment)['payment'];
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Pending, $manual->fresh()->status);
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(0, $this->sales($order));
        app(OrderStateMachine::class)->applyPaymentExpired($payment->fresh());
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        app(AdminOrderOperations::class)->completeManualOrder($order->fresh());
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(1, $this->sales($order));
    }

    public function test_refunding_original_money_does_not_refund_cancel_or_refill_the_manual_order(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $manual = $this->switch($customer, $payment)['payment'];
        app(AdminOrderOperations::class)->completeManualOrder($order->fresh());
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());
        $review = ManualFulfillmentReview::query()->where('payment_id', $payment->id)->sole();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        app(ManualFulfillmentResolutionService::class)->resolve($review, $actor, 'refund', 'Duplicate external money returned', 'REFUND-ORIGINAL', 'success');
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());

        $this->assertSame(PaymentStatus::Refunded, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $manual->fresh()->status);
        $this->assertSame(PaymentStatus::Paid, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::Completed, $order->fresh()->order_status);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(ManualFulfillmentReview::STATE_REFUNDED, $review->fresh()->state);
    }

    public function test_superseded_provider_remains_a_reconciliation_candidate_after_manual_completion(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $this->switch($customer, $payment);
        app(AdminOrderOperations::class)->completeManualOrder($order->fresh());
        $this->assertTrue(app(WijayaPayReconciliationQuery::class)->eligible()->whereKey($payment->id)->exists());
    }

    public function test_callbacks_require_matching_private_customer_source_and_confirmation_then_retire_old_qr(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $handler = app(TelegramCheckoutManualAlternativeCallbackHandler::class);
        $handler->handle($this->context($customer, $payment, 'ask', 701));
        $handler->handle($this->context($customer, $payment, 'ask', 700, 'group'));
        $handler->handle($this->context($customer, $payment, 'confirm', 700));
        $this->assertSame(1, $order->payments()->count());
        $handler->handle($this->context($customer, $payment, 'ask', 700));
        $handler->handle($this->context($customer, $payment, 'confirm', 701));
        $this->assertSame(1, $order->payments()->count());
        $handler->handle($this->context($customer, $payment, 'confirm', 123457));
        $handler->handle($this->context($customer, $payment, 'confirm', 123457));
        $this->assertSame(2, $order->payments()->count());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/deleteMessage') && (int) $request['message_id'] === 700);
    }

    public function test_no_order_preflight_failure_manual_confirmation_uses_original_checkout_key_and_cart_and_is_replay_safe(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0, 'price_amount' => 12_000]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 2);
        $selector = Mockery::mock(TelegramCheckoutPaymentIntentSelector::class);
        $selector->shouldReceive('select')->once()->andThrow(new CheckoutPaymentUnavailable('Channel inactive', reason: 'channel_inactive'));
        $this->app->instance(TelegramCheckoutPaymentIntentSelector::class, $selector);
        $handler = app(TelegramCheckoutCallbackHandler::class);
        $handler->handle(new TelegramCallbackContext($customer, '12345', 'first', 'checkout:current', 'private', 600));
        $this->assertDatabaseCount('orders', 0);
        $request = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->last()[0];
        $keyboard = $request['reply_markup'];
        $keyboard = is_string($keyboard) ? json_decode($keyboard, true) : $keyboard;
        $callback = $keyboard['inline_keyboard'][1][0]['callback_data'];
        $nonce = substr($callback, strlen('checkout:manual:ask:'));
        $handler->handle(new TelegramCallbackContext($customer, '12345', 'ask', $callback, 'private', 123457));
        $handler->handle(new TelegramCallbackContext($customer, '12345', 'foreign-confirm', 'checkout:manual:confirm:'.$nonce, 'private', 999));
        $this->assertDatabaseCount('orders', 0);
        $state = Cache::get('telegram:checkout-recovery:'.$customer->id.':'.$nonce);
        $handler->handle(new TelegramCallbackContext($customer, '12345', 'confirm', 'checkout:manual:confirm:'.$nonce, 'private', 123457));
        $handler->handle(new TelegramCallbackContext($customer, '12345', 'replay', 'checkout:manual:confirm:'.$nonce, 'private', 123457));
        $order = Order::query()->sole();
        $this->assertSame($state['checkoutKey'], $order->checkout_key);
        $this->assertSame($cart->id, $order->cart_id);
        $this->assertSame(24_000, $order->total_amount);
        $this->assertSame('manual', $order->payments()->sole()->provider);
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame('wijayapay', ManualPaymentSetting::current()->payment_mode);
    }

    public function test_retirement_http_runs_outside_monetary_transaction_and_paid_race_cannot_switch(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $baseline = DB::transactionLevel();
        $result = app(CheckoutManualAlternative::class)->switch($customer, $payment, function () use ($baseline, $payment): void {
            $this->assertSame($baseline, DB::transactionLevel());
            app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());
        });
        $this->assertSame('paid', $result['status']);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(1, $this->sales($order));
    }

    public function test_stale_retirement_worker_cannot_commit_after_lease_is_reclaimed(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $result = app(CheckoutManualAlternative::class)->switch($customer, $payment, function () use ($payment): void {
            $payment->fresh()->update([
                'manual_alternative_retirement_token' => (string) Str::uuid(),
                'manual_alternative_retirement_started_at' => now(),
            ]);
        });
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(1, $order->payments()->count());
        $this->assertNull($payment->fresh()->manual_alternative_at);
    }

    public function test_superseded_provider_expiry_never_expires_the_active_manual_order(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $manual = $this->switch($customer, $payment)['payment'];
        app(OrderStateMachine::class)->applyPaymentExpired($payment->fresh());
        $this->assertSame(PaymentStatus::Expired, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $manual->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(2, $product->fresh()->reserved_stock);
    }

    public function test_queued_original_instruction_cannot_expose_another_qr_after_switch(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        $this->switch($customer, $payment);
        app(CheckoutInstructionDeliveryPipeline::class)->deliver(
            $effect,
            app(TelegramCheckoutIntents::class),
        );
        $this->assertNull($effect->fresh()->sent_at);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
    }

    public function test_back_cancels_only_confirmation_without_switching_or_removing_original_qr(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $handler = app(TelegramCheckoutManualAlternativeCallbackHandler::class);
        $handler->handle($this->context($customer, $payment, 'ask', 700));
        $handler->handle($this->context($customer, $payment, 'back', 123457));
        $handler->handle($this->context($customer, $payment, 'confirm', 123457));
        $this->assertSame(1, $order->payments()->count());
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/deleteMessage') && (int) $request['message_id'] === 700);
    }

    public function test_migration_cannot_erase_existing_alternative_payment_provenance(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $this->switch($customer, $payment);
        $migration = require database_path('migrations/2026_11_10_000003_add_checkout_manual_alternative_to_payments.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove checkout manual-alternative provenance');
        $migration->down();
    }

    public function test_linked_manual_telegram_approval_requires_proof_and_matching_private_admin_source(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $manual = $this->switch($customer, $payment)['payment'];
        $admin = Customer::factory()->create(['telegram_id' => 999]);
        $handler = app(TelegramAdminPaymentCallbackHandler::class);
        $callback = 'admin:approve-payment:'.$order->id;
        $handler->handle(new TelegramCallbackContext($admin, '999', 'no-proof', $callback, 'private', 900));
        $this->assertSame(PaymentStatus::Pending, $manual->fresh()->status);
        app(TelegramManualPaymentIntents::class)->submitManualPaymentProof($customer, '12345', [
            'photo' => [['file_id' => 'proof-photo', 'width' => 800, 'height' => 800]],
        ], $order->invoice_number);
        $effect = TelegramOutboundEffect::query()->where('source_type', Payment::class)->where('source_id', $manual->id)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_MANUAL_PAYMENT_PROOF)->sole();
        $effect->forceFill(['state' => 'sent', 'telegram_message_id' => '900', 'sent_at' => now()])->save();
        $handler->handle(new TelegramCallbackContext($admin, '999', 'foreign-source', $callback, 'private', 901));
        $handler->handle(new TelegramCallbackContext($admin, '999', 'group-source', $callback, 'group', 900));
        $this->assertSame(PaymentStatus::Pending, $manual->fresh()->status);
        $handler->handle(new TelegramCallbackContext($admin, '999', 'approve', $callback, 'private', 900));
        $handler->handle(new TelegramCallbackContext($admin, '999', 'replay', $callback, 'private', 900));
        $this->assertSame(PaymentStatus::Paid, $manual->fresh()->status);
        $this->assertSame(3, $product->fresh()->stock);
        $this->assertSame(1, $this->sales($order));
    }

    #[DataProvider('automaticProviders')]
    public function test_supported_automatic_rail_definite_failure_can_select_real_manual_payment(string $provider): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider' => $provider, 'provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $result = $this->switch($customer, $payment);
        $this->assertSame('switched', $result['status']);
        $this->assertSame('manual', $result['payment']->provider);
        $this->assertSame($provider, $payment->fresh()->provider);
        $this->assertSame(2, $order->payments()->count());
    }

    public static function automaticProviders(): array
    {
        return [['wijayapay'], ['sakurupiah'], ['klikqris'], ['pakasir']];
    }

    public function test_expired_retirement_lease_cannot_commit_and_next_attempt_can_recover(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $result = app(CheckoutManualAlternative::class)->switch($customer, $payment, function (): void {
            $this->travel(61)->seconds();
        });
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(1, $order->payments()->count());
        $this->assertSame('switched', $this->switch($customer, $payment->fresh())['status']);
        $this->assertSame(2, $order->payments()->count());
    }

    public function test_authoritative_original_provider_reconciliation_after_switch_records_money_without_fulfilling(): void
    {
        [$customer, $order, $payment, $product] = $this->checkoutFixture();
        $this->providerStatus($payment, WijayaPayStatus::Pending);
        $manual = $this->switch($customer, $payment)['payment'];
        $this->providerStatus($payment->fresh(), WijayaPayStatus::Paid);
        app(WijayaPayCallbackProcessor::class)->reconcilePayment($payment->fresh());
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $manual->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(5, $product->fresh()->stock);
        $this->assertSame(2, $product->fresh()->reserved_stock);
        $this->assertSame(0, $this->sales($order));
        $this->assertDatabaseHas('manual_fulfillment_reviews', [
            'payment_id' => $payment->id,
            'reason' => ManualFulfillmentReview::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE,
            'state' => ManualFulfillmentReview::STATE_PENDING,
        ]);
    }

    private function switch(Customer $customer, Payment $payment): array
    {
        return app(CheckoutManualAlternative::class)->switch($customer, $payment, static function (): void {});
    }

    private function context(Customer $customer, Payment $payment, string $action, int $messageId, string $type = 'private'): TelegramCallbackContext
    {
        return new TelegramCallbackContext($customer, '12345', $action.'-'.$messageId, 'checkout-manual:'.$action.':'.$payment->id, $type, $messageId);
    }

    private function checkoutFixture(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'en']);
        $product = Product::factory()->create(['stock' => 5, 'reserved_stock' => 0, 'price_amount' => 12_000]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 2);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $order->forceFill(['manual_checkout_customer_notification_chat_id' => '12345', 'manual_checkout_customer_notification_message_id' => 700])->save();
        $payment = $order->payments()->sole();
        $payment->update([
            'provider' => 'wijayapay', 'method' => 'QRIS', 'merchant_ref' => 'WP-'.$order->id,
            'provider_reference' => 'TRX-'.$order->id, 'provider_payment_method' => 'QRIS',
            'provider_create_state' => 'created', 'provider_total_payment' => 24_000, 'provider_fee' => 0,
            'provider_created_at' => now(), 'provider_expires_at' => $order->expires_at,
            'provider_payload' => ['original' => 'preserved'],
            'provider_presentation' => ['mode' => 'qris_image', 'qris_payload' => null, 'qris_image_url' => 'https://wijayapay.com/qris/test.png', 'customer_pays_fee' => false, 'effective_expires_at' => $order->expires_at->toIso8601String()],
        ]);

        return [$customer, $order, $payment->fresh(), $product];
    }

    private function providerStatus(Payment $payment, WijayaPayStatus $status): void
    {
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->with($payment->merchant_ref)->andReturn(new WijayaPayStatusResponse(
            refId: $payment->merchant_ref, trxReference: $payment->provider_reference, paymentMethod: 'QRIS', status: $status,
            totalBayar: $payment->provider_total_payment, totalDibayar: $status === WijayaPayStatus::Paid ? $payment->provider_total_payment : null,
            totalFee: 0, amountReceived: $status === WijayaPayStatus::Paid ? $payment->amount : null,
            expiresAt: CarbonImmutable::instance($payment->provider_expires_at), updatedAt: CarbonImmutable::now(),
            sanitizedPayload: ['status_pembayaran' => $status->value, 'data' => ['ref_id' => $payment->merchant_ref]],
        ));
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);
    }

    public function test_original_money_review_cannot_fulfill_the_order_again(): void
    {
        [$customer, $order, $payment] = $this->checkoutFixture();
        $payment->update(['provider_reference' => null, 'provider_create_state' => 'create_failed']);
        $this->switch($customer, $payment);
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());
        $review = ManualFulfillmentReview::query()->where('payment_id', $payment->id)->sole();
        $actor = User::factory()->create(['role' => UserRole::Owner]);
        $this->expectException(InvalidOrderTransition::class);
        app(ManualFulfillmentResolutionService::class)->resolve($review, $actor, 'fulfill', 'Do not create a second fulfillment');
    }

    private function sales(Order $order): int
    {
        return InventoryMovement::query()->where('order_id', $order->id)->where('type', InventoryMovementType::Sale->value)->count();
    }
}
