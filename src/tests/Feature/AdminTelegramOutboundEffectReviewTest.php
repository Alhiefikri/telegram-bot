<?php

namespace Tests\Feature;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\UserRole;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendManualCheckoutNotifications;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\SalesProofPost;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminTelegramOutboundEffectReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_customer_and_non_admin_cannot_read_or_mutate_effect_reviews(): void
    {
        $effect = $this->unknownEffect();
        $customer = Customer::query()->findOrFail($effect->source->customer_id);
        $nonAdmin = User::factory()->create(['role' => null]);

        $this->get(route('admin.telegram-outbound-effects.index'))
            ->assertRedirect(route('admin.login'));

        $this->actingAs($customer, 'customer')
            ->get(route('admin.telegram-outbound-effects.index'))
            ->assertForbidden();

        $this->actingAs($nonAdmin)
            ->get(route('admin.telegram-outbound-effects.index'))
            ->assertForbidden();

        $this->actingAs($nonAdmin)
            ->post(route('admin.telegram-outbound-effects.mark-sent', $effect), [
                'telegram_message_id' => '123456',
            ])
            ->assertForbidden();

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
    }

    public function test_support_can_read_safe_review_metadata_but_cannot_resolve_it(): void
    {
        $effect = $this->unknownEffect([
            'effect_type' => 'receipt.credentials',
            'telegram_chat_id' => '987654321',
            'last_error_code' => 'telegram_transport_ambiguous',
            'last_error' => 'raw Telegram response with QR-LHIE-SECRET and credential@example.test|password',
            'outcome_metadata' => [
                'exception' => 'ConnectionException',
                'raw_payload' => 'QR-LHIE-SECRET',
                'credential' => 'credential@example.test|password',
                'bot_token' => '123456:SECRET-TOKEN',
            ],
        ]);
        $support = User::factory()->create(['role' => UserRole::Support]);

        $response = $this->actingAs($support)
            ->get(route('admin.telegram-outbound-effects.show', $effect));

        $response
            ->assertOk()
            ->assertSee('unknown')
            ->assertSee('receipt.credentials')
            ->assertSee($effect->source->invoice_number)
            ->assertSee('4321')
            ->assertSee('telegram_transport_ambiguous')
            ->assertDontSee('987654321')
            ->assertDontSee('QR-LHIE-SECRET')
            ->assertDontSee('credential@example.test|password')
            ->assertDontSee('123456:SECRET-TOKEN')
            ->assertDontSee('raw Telegram response');

        $this->actingAs($support)
            ->post(route('admin.telegram-outbound-effects.mark-sent', $effect), [
                'telegram_message_id' => '123456',
            ])
            ->assertForbidden();

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
    }

    public function test_owner_marks_an_ambiguous_effect_sent_without_calling_telegram_and_records_audit(): void
    {
        Http::fake();
        $effect = $this->unknownEffect();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.telegram-outbound-effects.mark-sent', $effect), [
                'telegram_message_id' => '777888',
            ])
            ->assertRedirect(route('admin.telegram-outbound-effects.show', $effect));

        $resolved = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Sent, $resolved->state);
        $this->assertSame('777888', $resolved->telegram_message_id);
        $this->assertNotNull($resolved->sent_at);
        $this->assertNotNull($resolved->manual_review_at);
        $this->assertSame('operator_marked_sent', $resolved->outcome_metadata['resolution']);
        $this->assertSame($owner->id, $resolved->outcome_metadata['resolution_actor_id']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telegram_outbound_effect.marked_sent',
            'subject_type' => TelegramOutboundEffect::class,
            'subject_id' => $effect->id,
            'user_id' => $owner->id,
        ]);
        Http::assertNothingSent();
    }

    public function test_manual_review_can_be_marked_sent_without_resending(): void
    {
        Http::fake();
        $effect = $this->unknownEffect(['state' => TelegramOutboundEffectState::ManualReview]);
        $owner = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($owner)
            ->post(route('admin.telegram-outbound-effects.mark-sent', $effect), [
                'telegram_message_id' => '111222',
            ])
            ->assertRedirect(route('admin.telegram-outbound-effects.show', $effect));

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidOperatorMessageIds')]
    public function test_operator_cannot_mark_an_effect_sent_with_an_invalid_message_id(string $messageId): void
    {
        $effect = $this->unknownEffect();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.telegram-outbound-effects.mark-sent', $effect), [
                'telegram_message_id' => $messageId,
            ])
            ->assertSessionHasErrors('telegram_message_id');

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertNull($effect->fresh()->telegram_message_id);
    }

    public function test_resend_requires_explicit_duplicate_risk_confirmation(): void
    {
        Queue::fake();
        $effect = $this->unknownEffect();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->from(route('admin.telegram-outbound-effects.show', $effect))
            ->post(route('admin.telegram-outbound-effects.resend', $effect), [
                'confirm_duplicate_risk' => '0',
            ])
            ->assertRedirect(route('admin.telegram-outbound-effects.show', $effect))
            ->assertSessionHasErrors('confirm_duplicate_risk');

        $this->assertDatabaseCount('telegram_outbound_effects', 1);
        Queue::assertNothingPushed();
    }

    public function test_explicit_resend_creates_a_new_version_and_keeps_the_ambiguous_effect_immutable(): void
    {
        Queue::fake();
        $effect = $this->unknownEffect([
            'effect_group_key' => 'order:review-group',
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
            'outcome_metadata' => ['exception' => 'ConnectionException'],
        ]);
        $original = $effect->getAttributes();
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.telegram-outbound-effects.resend', $effect), [
                'confirm_duplicate_risk' => '1',
            ])
            ->assertRedirect();

        $effect->refresh();
        $this->assertSame($original['effect_key'], $effect->effect_key);
        $this->assertSame($original['state'], $effect->getRawOriginal('state'));
        $this->assertNull($effect->supersedes_effect_id);

        $resend = TelegramOutboundEffect::query()
            ->where('supersedes_effect_id', $effect->id)
            ->sole();

        $this->assertSame(TelegramOutboundEffectState::Pending, $resend->state);
        $this->assertSame('order:review-group', $resend->effect_group_key);
        $this->assertSame(2, $resend->sequence);
        $this->assertTrue($resend->outcome_metadata['duplicate_risk_acknowledged']);
        $this->assertSame('explicit_resend', $resend->outcome_metadata['delivery_context']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'telegram_outbound_effect.resent',
            'subject_type' => TelegramOutboundEffect::class,
            'subject_id' => $effect->id,
            'user_id' => $owner->id,
        ]);
        Queue::assertPushed(
            SendManualCheckoutNotifications::class,
            fn (SendManualCheckoutNotifications $job): bool => $job->effectId === $resend->id,
        );

        $this->actingAs($owner)
            ->get(route('admin.telegram-outbound-effects.show', $resend))
            ->assertOk()
            ->assertSee('Duplicate-risk acknowledged');
    }

    public function test_explicit_manual_resend_is_not_blocked_by_the_old_legacy_claim(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 2, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $effect = TelegramOutboundEffect::query()->sole();
        $effect->forceFill([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
        ])->save();
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        $resend = app(TelegramOutboundEffectService::class)->resend($effect, $owner);

        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response(['ok' => true, 'result' => ['message_id' => 909090]]),
        ]);

        (new SendManualCheckoutNotifications($order->id, null, $resend->id))
            ->handle(app(TelegramCheckoutIntents::class));

        $this->assertSame(TelegramOutboundEffectState::Sent, $resend->fresh()->state);
        $this->assertSame('909090', $resend->fresh()->telegram_message_id);
    }

    public function test_sales_proof_review_resend_creates_an_explicit_version_and_queues_only_that_effect(): void
    {
        Queue::fake();
        $order = Order::factory()->create();
        SalesProofPost::create([
            'order_id' => $order->id,
            'status' => SalesProofPost::STATUS_UNKNOWN,
            'attempts' => 1,
        ]);
        $effect = TelegramOutboundEffect::factory()->create([
            'effect_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_group_key' => TelegramOutboundEffectService::salesProofEffectKey($order->id),
            'effect_type' => TelegramOutboundEffectService::TYPE_SALES_PROOF,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'target_type' => 'store_channel',
            'telegram_chat_id' => '-100123',
            'queue' => 'notifications',
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinute(),
        ]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);

        $this->actingAs($owner)
            ->post(route('admin.telegram-outbound-effects.resend', $effect), [
                'confirm_duplicate_risk' => '1',
            ])
            ->assertRedirect();

        $resend = TelegramOutboundEffect::query()
            ->where('supersedes_effect_id', $effect->id)
            ->sole();

        $this->assertSame(TelegramOutboundEffectService::TYPE_SALES_PROOF, $resend->effect_type);
        $this->assertSame(2, $resend->sequence);
        $this->assertTrue($resend->outcome_metadata['duplicate_risk_acknowledged']);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        Queue::assertPushed(
            PublishSalesProofPost::class,
            fn (PublishSalesProofPost $job): bool => $job->effectId === $resend->id
                && $job->orderId === $order->id,
        );
    }

    private function unknownEffect(array $attributes = []): TelegramOutboundEffect
    {
        $order = Order::factory()->create();

        return TelegramOutboundEffect::factory()->create(array_merge([
            'effect_key' => 'review:'.$order->id.':'.fake()->unique()->uuid(),
            'effect_group_key' => 'review:'.$order->id,
            'source_type' => Order::class,
            'source_id' => $order->id,
            'telegram_chat_id' => '987654321',
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now()->subMinutes(5),
            'last_error_code' => 'telegram_transport_ambiguous',
            'last_error' => 'Telegram delivery outcome is unknown and requires review.',
            'outcome_metadata' => ['exception' => 'ConnectionException'],
        ], $attributes));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidOperatorMessageIds(): iterable
    {
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'fractional' => ['1.5'];
        yield 'empty' => [''];
        yield 'non numeric' => ['not-a-message-id'];
    }
}
