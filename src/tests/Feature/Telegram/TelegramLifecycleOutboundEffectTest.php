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
use App\Services\Telegram\AdminOrderNotificationDeliveryPipeline;
use App\Services\Telegram\ManualPaymentProofDeliveryPipeline;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
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
        $this->assertSame('sendPhoto', $effect->telegram_method);
        $this->assertSame(TelegramOutboundEffectService::TEMPLATE_SALES_PROOF, $effect->template_version);
        $this->assertSame('invoice', $effect->outcome_metadata['unit_role'] ?? null);
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

    public function test_failed_sales_proof_effect_projects_failure_when_post_never_entered_sending(): void
    {
        $order = Order::factory()->create();
        $post = SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_PENDING,
            'attempts' => 0,
        ]);
        TelegramOutboundEffect::factory()->create([
            'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'state' => TelegramOutboundEffectState::Failed,
            'attempts' => 3,
            'last_error' => 'Telegram delivery failed before completion.',
            'last_error_code' => 'telegram_attempts_exhausted',
        ]);

        app(TelegramOutboundEffectService::class)->materializeSalesProofPostEffect($order);

        $this->assertSame(SalesProofPost::STATUS_FAILED, $post->fresh()->status);
        $this->assertSame(3, $post->fresh()->attempts);
    }

    public function test_expiry_records_one_idempotent_admin_effect_on_the_notifications_queue(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $order->forceFill(['expires_at' => now()->subMinute()])->save();
        $order->payments()->sole()->update(['expired_at' => now()->subMinute()]);
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

    public function test_recovery_dispatches_an_existing_pending_admin_effect(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);
        $this->assertNotNull($effect);

        Queue::fake();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])
            ->assertSuccessful();

        Queue::assertPushed(SendAdminOrderPendingNotification::class, 1);
        Queue::assertPushed(
            SendAdminOrderPendingNotification::class,
            fn (SendAdminOrderPendingNotification $job): bool => $job->orderId === $order->id
                && $job->effectId === $effect->id,
        );
    }

    public function test_ineligible_admin_notification_becomes_terminal_and_recovery_does_not_redispatch_it(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);
        $this->assertNotNull($effect);

        $order->forceFill([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ])->save();

        app(AdminOrderNotificationDeliveryPipeline::class)->deliver($effect);

        $failed = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $failed->state);
        $this->assertSame('admin_notification_not_eligible', $failed->last_error_code);
        $this->assertNull($failed->next_attempt_at);

        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $this->assertTrue($ledger->isTerminalFailure($failed));
        $this->assertFalse(
            $ledger->readyToDispatchQuery(now()->addDay())->whereKey($failed->id)->exists(),
        );

        Queue::fake();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();
        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        Queue::assertNotPushed(SendAdminOrderPendingNotification::class);
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

        $pipeline = app(AdminOrderNotificationDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame(1, $sendCount);
        $this->assertNull($order->fresh()->admin_paid_order_notified_at);
    }

    #[DataProvider('invalidAdminNotificationTelegramResponses')]
    public function test_invalid_admin_notification_telegram_responses_never_mark_the_effect_sent(array $response): void
    {
        $this->configureAdminTarget();
        Queue::fake();
        $order = $this->checkoutOrder();
        app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->sole());
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PAID);
        Http::fake(['https://api.telegram.org/*/sendMessage' => Http::response($response)]);

        try {
            app(AdminOrderNotificationDeliveryPipeline::class)->deliver($effect);
        } catch (\Throwable) {
            // Queue workers retry definite delivery failures through the job.
        }

        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertNull($effect->fresh()->telegram_message_id);
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

        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
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

        $pipeline = app(ManualPaymentProofDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNotNull($payment->fresh()->payment_proof_forwarded_at);
        $this->assertSame('999', $payment->fresh()->payment_proof_admin_chat_id);
        $adminPhotos = Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendPhoto')
            && (string) ($request['chat_id'] ?? '') === '999');
        $this->assertCount(1, $adminPhotos);
    }

    public function test_recovery_repairs_a_sent_manual_payment_proof_projection_without_resending_it(): void
    {
        $this->configureAdminTarget();
        Queue::fake();
        [$order, $payment] = $this->manualProof();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordManualPaymentProof($order, $payment);
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $effect->getKey());
        $this->assertNotNull($claimed);
        $finalized = $ledger->finalizeSent(
            (string) $effect->getKey(),
            (string) $claimed->claim_token,
            '901',
        );
        $this->assertNotNull($finalized);
        $this->assertNull($payment->fresh()->payment_proof_forwarded_at);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $repairedPayment = $payment->fresh();
        $this->assertNotNull($repairedPayment->payment_proof_forwarded_at);
        $this->assertSame('999', $repairedPayment->payment_proof_admin_chat_id);
        Queue::assertNotPushed(ForwardManualPaymentProof::class);

        $forwardedAt = $repairedPayment->payment_proof_forwarded_at;
        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertTrue($forwardedAt->equalTo($repairedPayment->fresh()->payment_proof_forwarded_at));
        Queue::assertNotPushed(ForwardManualPaymentProof::class);
    }

    public function test_recovery_repairs_a_sent_sales_proof_projection_without_resending_it(): void
    {
        StoreChannelSetting::create([
            'singleton_key' => StoreChannelSetting::SINGLETON_KEY,
            'sales_proof_enabled' => true,
            'telegram_chat_id' => '-100123',
        ]);
        Queue::fake();
        Http::preventStrayRequests();

        $order = $this->checkoutOrder();
        app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->sole());

        $post = $order->fresh()->salesProofPost()->firstOrFail();
        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_SALES_PROOF);
        $this->assertNotNull($effect);

        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $effect->getKey());
        $this->assertNotNull($claimed);
        $finalized = $ledger->finalizeSent(
            (string) $effect->getKey(),
            (string) $claimed->claim_token,
            '901',
        );
        $this->assertNotNull($finalized);
        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertSame(SalesProofPost::STATUS_PENDING, $post->fresh()->status);
        $this->assertNull($post->fresh()->telegram_message_id);
        Queue::assertPushed(PublishSalesProofPost::class, 1);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $repairedPost = $post->fresh();
        $this->assertSame(SalesProofPost::STATUS_SENT, $repairedPost->status);
        $this->assertSame('901', $repairedPost->telegram_message_id);
        $this->assertSame((int) $finalized->attempts, (int) $repairedPost->attempts);
        $this->assertNotNull($repairedPost->sent_at);
        $this->assertTrue($finalized->sent_at->equalTo($repairedPost->sent_at));
        Queue::assertPushed(PublishSalesProofPost::class, 1);

        $sentAt = $repairedPost->sent_at;
        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertTrue($sentAt->equalTo($post->fresh()->sent_at));
        Queue::assertPushed(PublishSalesProofPost::class, 1);
    }

    public function test_recovery_repairs_sent_admin_order_projections_without_redispatching_them(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $pendingOrder = $this->checkoutOrder();
        $pendingEffect = app(TelegramOutboundEffectService::class)
            ->forOrder($pendingOrder->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);
        $this->assertNotNull($pendingEffect);
        $pendingFinalized = $this->finalizeSentEffect($pendingEffect, '901');

        $paidOrder = $this->checkoutOrder(telegramId: 12346);
        app(OrderStateMachine::class)->applyPaymentPaid($paidOrder->payments()->sole());
        $paidEffect = app(TelegramOutboundEffectService::class)
            ->forOrder($paidOrder->id, TelegramOutboundEffectService::TYPE_ADMIN_PAID);
        $this->assertNotNull($paidEffect);
        $paidFinalized = $this->finalizeSentEffect($paidEffect, '902');

        $expiredOrder = $this->checkoutOrder(telegramId: 12347);
        $expiredOrder->forceFill(['expires_at' => now()->subMinute()])->save();
        $expiredOrder->payments()->sole()->update(['expired_at' => now()->subMinute()]);
        app(OrderStateMachine::class)->applyPendingExpiry($expiredOrder);
        $expiredEffect = app(TelegramOutboundEffectService::class)
            ->forOrder($expiredOrder->id, TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED);
        $this->assertNotNull($expiredEffect);
        $expiredFinalized = $this->finalizeSentEffect($expiredEffect, '903');

        $this->assertNull($pendingOrder->fresh()->admin_pending_order_notified_at);
        $this->assertNull($paidOrder->fresh()->admin_paid_order_notified_at);
        $this->assertNull($expiredOrder->fresh()->admin_expired_order_notified_at);

        Queue::fake();
        Http::preventStrayRequests();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])
            ->assertSuccessful();

        $repairedPending = $pendingOrder->fresh();
        $this->assertNotNull($repairedPending->admin_pending_order_notified_at);
        $this->assertTrue($pendingFinalized->sent_at->equalTo($repairedPending->admin_pending_order_notified_at));
        $this->assertSame('999', $repairedPending->admin_pending_order_notification_chat_id);
        $this->assertSame(901, $repairedPending->admin_pending_order_notification_message_id);
        $this->assertNull($repairedPending->admin_pending_order_notification_deleted_at);
        $this->assertNotNull($paidOrder->fresh()->admin_paid_order_notified_at);
        $this->assertTrue($paidFinalized->sent_at->equalTo($paidOrder->fresh()->admin_paid_order_notified_at));
        $this->assertNotNull($expiredOrder->fresh()->admin_expired_order_notified_at);
        $this->assertTrue($expiredFinalized->sent_at->equalTo($expiredOrder->fresh()->admin_expired_order_notified_at));

        Queue::assertNotPushed(
            SendAdminOrderPendingNotification::class,
            fn (SendAdminOrderPendingNotification $job): bool => $job->effectId === $pendingEffect->id,
        );
        Queue::assertNotPushed(SendAdminOrderPaidNotification::class);
        Queue::assertNotPushed(SendAdminOrderExpiredNotification::class);

        $pendingNotifiedAt = $repairedPending->admin_pending_order_notified_at;
        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])
            ->assertSuccessful();
        $this->assertTrue($pendingNotifiedAt->equalTo($pendingOrder->fresh()->admin_pending_order_notified_at));
        Queue::assertNotPushed(
            SendAdminOrderPendingNotification::class,
            fn (SendAdminOrderPendingNotification $job): bool => $job->effectId === $pendingEffect->id,
        );
    }

    public function test_recovery_preserves_recorded_admin_pending_cleanup_projection(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $effect = app(TelegramOutboundEffectService::class)
            ->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);
        $this->assertNotNull($effect);
        $this->finalizeSentEffect($effect, '904');

        $deletedAt = now()->subMinute()->startOfSecond();
        $order->forceFill(['admin_pending_order_notification_deleted_at' => $deletedAt])->save();
        Queue::fake();
        Http::preventStrayRequests();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $repaired = $order->fresh();
        $this->assertNotNull($repaired->admin_pending_order_notified_at);
        $this->assertSame('999', $repaired->admin_pending_order_notification_chat_id);
        $this->assertSame(904, $repaired->admin_pending_order_notification_message_id);
        $this->assertTrue($deletedAt->equalTo($repaired->admin_pending_order_notification_deleted_at));
        Queue::assertNotPushed(SendAdminOrderPendingNotification::class);
    }

    public function test_recovery_repairs_only_the_latest_sent_admin_order_effect_version(): void
    {
        $this->configureAdminTarget();
        Queue::fake();

        $order = $this->checkoutOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $firstEffect = $outboundEffects->forOrder($order->id, TelegramOutboundEffectService::TYPE_ADMIN_PENDING);
        $this->assertNotNull($firstEffect);
        $this->finalizeSentEffect($firstEffect, '905');

        $secondEffect = TelegramOutboundEffect::factory()->create([
            'effect_key' => $firstEffect->effect_key.':resend:v2',
            'effect_group_key' => $firstEffect->effect_group_key,
            'supersedes_effect_id' => $firstEffect->id,
            'sequence' => 2,
            'effect_type' => TelegramOutboundEffectService::TYPE_ADMIN_PENDING,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'target_type' => 'admin',
            'telegram_chat_id' => '999',
            'queue' => 'notifications',
            'telegram_method' => 'sendMessage',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_ADMIN_ORDER_PENDING,
            'state' => TelegramOutboundEffectState::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'outcome_metadata' => [
                'delivery_context' => 'explicit_resend',
                'duplicate_risk_acknowledged' => true,
            ],
        ]);
        $secondFinalized = $this->finalizeSentEffect($secondEffect, '906');

        Queue::fake();
        Http::preventStrayRequests();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $repaired = $order->fresh();
        $this->assertSame(906, $repaired->admin_pending_order_notification_message_id);
        $this->assertTrue($secondFinalized->sent_at->equalTo($repaired->admin_pending_order_notified_at));
        Queue::assertNotPushed(SendAdminOrderPendingNotification::class);
    }

    public function test_recovery_leaves_unknown_and_manual_review_sales_proof_effects_untouched(): void
    {
        Queue::fake();
        $rows = [];

        foreach ([
            'unknown' => [
                'post_status' => SalesProofPost::STATUS_UNKNOWN,
                'effect_state' => TelegramOutboundEffectState::Unknown,
            ],
            'manual_review' => [
                'post_status' => SalesProofPost::STATUS_MANUAL_REVIEW,
                'effect_state' => TelegramOutboundEffectState::ManualReview,
            ],
        ] as $case) {
            $order = Order::factory()->create();
            $post = SalesProofPost::create([
                'order_id' => $order->id,
                'status' => $case['post_status'],
                'attempts' => 2,
                'last_error' => 'requires operator review',
            ]);
            $effect = TelegramOutboundEffect::factory()->create([
                'effect_key' => 'sales-proof-recovery:'.$order->id,
                'effect_group_key' => 'sales-proof-recovery:'.$order->id,
                'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
                'source_type' => Order::class,
                'source_id' => (string) $order->id,
                'target_type' => 'store_channel',
                'telegram_chat_id' => '-100123',
                'queue' => 'notifications',
                'telegram_method' => 'sendPhoto',
                'template_version' => TelegramOutboundEffectService::TEMPLATE_SALES_PROOF,
                'state' => $case['effect_state'],
                'attempts' => 2,
                'last_error' => 'requires operator review',
            ]);
            $rows[] = [$post, $effect, $case];
        }

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        foreach ($rows as [$post, $effect, $case]) {
            $this->assertSame($case['post_status'], $post->fresh()->status);
            $this->assertSame($case['effect_state'], $effect->fresh()->state);
        }
        Queue::assertNotPushed(PublishSalesProofPost::class);
    }

    public function test_recovery_repairs_a_late_manual_payment_proof_without_changing_payment_evidence(): void
    {
        $this->configureAdminTarget();
        Queue::fake();
        [$order, $payment] = $this->manualProof();
        $order->forceFill([
            'order_status' => OrderStatus::Expired,
            'payment_status' => PaymentStatus::Expired,
        ])->save();
        $payment->forceFill([
            'method' => 'BINANCE_PAY_ID',
            'status' => PaymentStatus::Expired,
        ])->save();

        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordManualPaymentProof($order->fresh(), $payment->fresh());
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $effect->getKey());
        $this->assertNotNull($claimed);
        $finalized = $ledger->finalizeSent(
            (string) $effect->getKey(),
            (string) $claimed->claim_token,
            '902',
        );
        $this->assertNotNull($finalized);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $repairedPayment = $payment->fresh();
        $this->assertSame(PaymentStatus::Expired, $repairedPayment->status);
        $this->assertSame(OrderStatus::Expired, $order->fresh()->order_status);
        $this->assertTrue($effect->fresh()->outcome_metadata['late_evidence']);
        $this->assertNotNull($repairedPayment->payment_proof_forwarded_at);
        Queue::assertNotPushed(ForwardManualPaymentProof::class);
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

        $pipeline = app(ManualPaymentProofDeliveryPipeline::class);
        $pipeline->deliver($effect);
        $pipeline->deliver($effect->fresh());

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

    #[DataProvider('invalidManualProofTelegramResponses')]
    public function test_invalid_manual_payment_proof_telegram_responses_are_not_marked_sent(array $response): void
    {
        $this->configureAdminTarget();
        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*/sendPhoto' => Http::response($response)]);
        [$order, $payment] = $this->manualProof();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $effect = $outboundEffects->recordManualPaymentProof($order, $payment);

        try {
            app(ManualPaymentProofDeliveryPipeline::class)->deliver($effect);
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

    private function finalizeSentEffect(TelegramOutboundEffect $effect, string $messageId): TelegramOutboundEffect
    {
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $claimed = $ledger->claim((string) $effect->getKey());
        if ($claimed === null) {
            $this->fail('The outbound effect could not be claimed for the regression setup.');
        }

        $finalized = $ledger->finalizeSent(
            (string) $effect->getKey(),
            (string) $claimed->claim_token,
            $messageId,
        );
        if ($finalized === null) {
            $this->fail('The outbound effect could not be finalized for the regression setup.');
        }

        return $finalized;
    }

    private function checkoutOrder($paymentIntent = null, int $telegramId = 12345): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => $telegramId, 'username' => 'buyer'.$telegramId]);
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

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidAdminNotificationTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'negative' => [['ok' => true, 'result' => ['message_id' => -1]]];
        yield 'fractional' => [['ok' => true, 'result' => ['message_id' => 1.5]]];
        yield 'empty' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidManualProofTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'negative' => [['ok' => true, 'result' => ['message_id' => -1]]];
        yield 'fractional' => [['ok' => true, 'result' => ['message_id' => 1.5]]];
        yield 'empty' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }
}
