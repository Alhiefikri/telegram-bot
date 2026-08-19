<?php

namespace Tests\Feature\Telegram;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Jobs\ForwardManualPaymentProof;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramOutboundEffect;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\TelegramBotTestSupport;

#[Group('concurrency')]
class TelegramLifecycleOutboundEffectTest extends TelegramBotTestSupport
{
    public function test_checkout_records_an_idempotent_pending_admin_effect_on_the_notifications_queue(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);

        $this->assertNotNull($effect);
        $this->assertSame(
            TelegramOutboundEffectService::adminOrderEffectKey($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING),
            $effect->effect_key,
        );
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame('notifications', $effect->queue);
        $this->assertSame('admin', $effect->target_type);
        $this->assertSame('999', $effect->telegram_chat_id);

        $this->assertTrue($outboundEffects->dispatchPendingAdminNotification($order));
        Queue::assertPushedOn(
            'notifications',
            SendAdminOrderPendingNotification::class,
            fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );

        $sameEffect = $outboundEffects->recordAdminOrderPendingNotification($order->fresh());
        $this->assertSame($effect->id, $sameEffect?->id);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_key', $effect->effect_key)
            ->count());
    }

    public function test_checkout_rollback_does_not_leave_an_orphan_pending_admin_effect(): void
    {
        $this->configureAdminTarget();

        try {
            DB::transaction(function (): void {
                $this->checkoutOrder();

                throw new \RuntimeException('rollback lifecycle transaction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('rollback lifecycle transaction', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
    }

    public function test_paid_lifecycle_records_one_paid_effect_atomically_and_replays_without_a_burst(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $payment = $order->payments()->sole();

        $first = app(OrderStateMachine::class)->applyPaymentPaid($payment);
        $replay = app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());
        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PAID);

        $this->assertTrue($first->newlyApplied);
        $this->assertFalse($replay->newlyApplied);
        $this->assertSame(OrderStatus::Processing, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(1, InventoryMovement::query()->where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count());
        $this->assertNotNull($effect);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame('notifications', $effect->queue);
        Queue::assertPushed(SendAdminOrderPaidNotification::class, 1);
        Queue::assertPushed(
            SendAdminOrderPaidNotification::class,
            fn (SendAdminOrderPaidNotification $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_paid_lifecycle_records_one_sales_proof_effect_and_dispatches_it_after_commit(): void
    {
        $this->configureAdminTarget();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        Queue::fake();

        $order = $this->checkoutOrder();
        $payment = $order->payments()->sole();

        app(OrderStateMachine::class)->applyPaymentPaid($payment);
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());

        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_SALES_PROOF);

        $this->assertNotNull($effect);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame('store_channel', $effect->target_type);
        $this->assertSame('notifications', $effect->queue);
        $this->assertSame(1, SalesProofPost::query()->where('order_id', $order->id)->count());
        Queue::assertPushed(
            PublishSalesProofPost::class,
            fn (PublishSalesProofPost $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_paid_lifecycle_rollback_reverts_business_state_and_paid_effect(): void
    {
        $this->configureAdminTarget();
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        config()->set('queue.default', 'database');

        $order = $this->checkoutOrder();
        $payment = $order->payments()->sole();

        DB::beginTransaction();
        app(OrderStateMachine::class)->applyPaymentPaid($payment);
        DB::rollBack();

        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(0, InventoryMovement::query()->where('order_id', $order->id)->where('type', InventoryMovementType::Sale)->count());
        $this->assertDatabaseMissing('telegram_outbound_effects', [
            'source_id' => $order->id,
            'effect_type' => TelegramOutboundEffectService::TYPE_ADMIN_PAID,
        ]);
        $this->assertDatabaseMissing('telegram_outbound_effects', [
            'source_id' => $order->id,
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
        ]);
        $this->assertDatabaseMissing('sales_proof_posts', ['order_id' => $order->id]);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_expiry_records_one_idempotent_admin_effect_on_the_notifications_queue(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder(CheckoutPaymentIntents::manualQris(now()->subMinute()));
        $first = app(OrderStateMachine::class)->applyPendingExpiry($order);
        $replay = app(OrderStateMachine::class)->applyPendingExpiry($order->fresh());
        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED);

        $this->assertTrue($first->newlyApplied);
        $this->assertFalse($replay->newlyApplied);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertSame(PaymentStatus::Expired, $order->fresh()->payment_status);
        $this->assertNotNull($effect);
        $this->assertSame(TelegramOutboundEffectState::Pending, $effect->state);
        $this->assertSame('notifications', $effect->queue);
        Queue::assertPushed(SendAdminOrderExpiredNotification::class, 1);
        Queue::assertPushed(
            SendAdminOrderExpiredNotification::class,
            fn (SendAdminOrderExpiredNotification $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_paid_admin_effect_ambiguous_delivery_stays_unknown_without_a_duplicate_send(): void
    {
        $this->configureAdminTarget();
        Queue::fake();
        $order = $this->checkoutOrder();
        app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->sole());
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PAID);
        $sendCount = 0;

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        $outboundEffects->processAdminOrderNotification($effect);
        $outboundEffects->processAdminOrderNotification($effect->fresh());

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame(1, $sendCount);
        $this->assertNull($order->fresh()->admin_paid_order_notified_at);
    }

    public function test_manual_payment_proof_effect_is_attachment_safe_and_idempotent(): void
    {
        $this->configureAdminTarget();
        Queue::fake();
        [$order, $payment] = $this->manualProof();
        $outboundEffects = app(TelegramOutboundEffectService::class);

        $effect = $outboundEffects->recordManualPaymentProof($order, $payment);
        $sameEffect = $outboundEffects->recordManualPaymentProof($order->fresh(), $payment->fresh());

        $this->assertNotNull($effect);
        $this->assertSame($effect->id, $sameEffect?->id);
        $this->assertSame('notifications', $effect->queue);
        $this->assertSame(Payment::class, $effect->source_type);
        $this->assertSame($payment->id, $effect->source_id);
        $this->assertSame('telegram_file_reference', $effect->outcome_metadata['attachment_type']);
        $this->assertTrue($effect->outcome_metadata['attachment_present']);

        $outboundEffects->dispatch($effect);
        Queue::assertPushedOn(
            'notifications',
            ForwardManualPaymentProof::class,
            fn (ForwardManualPaymentProof $job): bool => $job->paymentId === $payment->id
                && $job->effectId === $effect->id,
        );

        $summary = $outboundEffects->reviewSummary($effect);
        $encodedSummary = json_encode($summary, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('proof-file-secret', $encodedSummary);
        $this->assertSame('telegram_file_reference', $summary['metadata']['attachment_type']);
    }

    public function test_manual_payment_proof_success_marks_the_effect_and_never_sends_again(): void
    {
        $this->configureAdminTarget();
        $this->fakeTelegramHttp();
        [$order, $payment] = $this->manualProof();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordManualPaymentProof($order, $payment);

        $outboundEffects->processManualPaymentProof($effect);
        $outboundEffects->processManualPaymentProof($effect->fresh());

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNotNull($payment->fresh()->payment_proof_forwarded_at);
        $this->assertSame('999', $payment->fresh()->payment_proof_admin_chat_id);
        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(1, $adminPhotos);
    }

    public function test_manual_payment_proof_ambiguous_delivery_becomes_unknown_without_a_duplicate_forward(): void
    {
        $this->configureAdminTarget();
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$sendCount): never {
                $sendCount++;
                throw new ConnectionException('connection closed after Telegram accepted the request');
            },
            'https://api.telegram.org/*' => Http::response(['ok' => true]),
        ]);
        [$order, $payment] = $this->manualProof();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordManualPaymentProof($order, $payment);

        $outboundEffects->processManualPaymentProof($effect);
        $outboundEffects->processManualPaymentProof($effect->fresh());

        $freshEffect = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Unknown, $freshEffect->state);
        $this->assertSame('telegram_transport_ambiguous', $freshEffect->last_error_code);
        $this->assertNull($payment->fresh()->payment_proof_forwarded_at);
        $this->assertSame(1, $sendCount);
        $this->assertStringNotContainsString(
            'proof-file-secret',
            json_encode(app(TelegramOutboundEffectService::class)->reviewSummary($freshEffect), JSON_THROW_ON_ERROR),
        );
    }

    public function test_numeric_message_id_from_unsuccessful_telegram_response_is_not_marked_sent(): void
    {
        $this->configureAdminTarget();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => false,
                'result' => ['message_id' => 123],
            ]),
        ]);
        [$order, $payment] = $this->manualProof();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordManualPaymentProof($order, $payment);

        try {
            $outboundEffects->processManualPaymentProof($effect);
        } catch (\Throwable) {
            // Queue workers retry definite delivery failures through the job.
        }

        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertNull($payment->fresh()->payment_proof_forwarded_at);
    }

    private function configureAdminTarget(): void
    {
        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'admin_telegram_chat_id' => '999',
            'is_active' => true,
        ]);
    }

    private function checkoutOrder($paymentIntent = null): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);

        return app(OrderCheckoutService::class)->checkout(
            $cart,
            $paymentIntent ?? CheckoutPaymentIntents::manualQris(),
        );
    }

    /** @return array{0: Order, 1: Payment} */
    private function manualProof(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'username' => 'buyer', 'locale' => 'id']);
        $order = Order::factory()->for($customer)->create();
        $payment = Payment::factory()->for($order)->create([
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'status' => PaymentStatus::Pending,
            'payment_proof_file_id' => 'proof-file-secret',
            'payment_proof_submitted_at' => now(),
        ]);

        return [$order->fresh(['customer', 'orderItems']), $payment->fresh()];
    }
}
