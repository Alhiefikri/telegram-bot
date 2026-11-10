<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TelegramBotTestSupport;

class PaymentProofIntakeTest extends TelegramBotTestSupport
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00 UTC');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::create(['payment_mode' => 'manual', 'admin_telegram_chat_id' => '999', 'is_active' => true]);
    }

    #[TestWith(['id', null])]
    #[TestWith(['en', null])]
    #[TestWith(['ms', null])]
    #[TestWith(['id', 'Sudah ditransfer'])]
    #[TestWith(['en', 'Paid from my bank'])]
    #[TestWith(['ms', 'Sudah bayar'])]
    #[TestWith(['id', '/payment'])]
    #[TestWith(['en', '/payment'])]
    #[TestWith(['ms', '/payment'])]
    public function test_photo_is_saved_and_forwarded_without_marking_the_order_paid(string $locale, ?string $caption): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $locale]);
        [$order, $payment] = $this->manualOrder($customer);

        $this->postTelegramWebhook($this->photo(98001, $caption))->assertOk();

        $this->assertOrderProof($order, $payment);
        $this->assertAdminProof('large-photo', 'admin:approve-payment:'.$order->id, 'admin:reject-payment:'.$order->id);
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_explicit_reference_selects_the_target_among_orders_and_top_ups(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        [$order, $payment] = $this->manualOrder($customer);
        [, $otherPayment] = $this->manualOrder($customer);
        $topUp = $this->manualTopUp($customer);
        $reference = $type === 'order' ? $order->invoice_number : 'topup:'.$topUp->id;

        $this->postTelegramWebhook($this->photo(98002, '/payment '.$reference))->assertOk();

        $this->assertNull($otherPayment->fresh()->payment_proof_submitted_at);
        if ($type === 'order') {
            $this->assertOrderProof($order, $payment);
            $this->assertNull($topUp->fresh()->payment_proof_submitted_at);
            $this->assertAdminProof('large-photo', 'admin:approve-payment:'.$order->id, 'admin:reject-payment:'.$order->id);
        } else {
            $this->assertTopUpProof($topUp);
            $this->assertNull($payment->fresh()->payment_proof_submitted_at);
            $this->assertAdminProof('large-photo', 'admin:topup-approve:'.$topUp->id, 'admin:topup-reject:'.$topUp->id);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_ambiguous_photo_or_command_offers_all_targets_without_saving_any_proof(bool $commandOnly): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        [$order, $payment] = $this->manualOrder($customer);
        $topUp = $this->manualTopUp($customer);

        $update = $commandOnly ? $this->messageUpdate(98003, '/payment') : $this->photo(98003);
        $this->postTelegramWebhook($update)->assertOk();

        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertNull($topUp->fresh()->payment_proof_submitted_at);
        $this->assertNoAdminProof();
        $choices = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === '12345'
            && isset($request['reply_markup']['inline_keyboard']));
        $this->assertCount(1, $choices);
        $callbacks = collect($choices->first()[0]['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data')->all();
        $this->assertEqualsCanonicalizing(['proof:order:'.$order->id, 'proof:topup:'.$topUp->id], $callbacks);
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_chooser_callback_requires_a_bound_photo_reply_before_forwarding(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        [$order, $payment] = $this->manualOrder($customer);
        $topUp = $this->manualTopUp($customer);
        $targetId = $type === 'order' ? $order->id : $topUp->id;
        $this->postTelegramWebhook($this->photo(98004))->assertOk();

        $this->postTelegramWebhook($this->callbackUpdate(98005, 'choose-proof', 'proof:'.$type.':'.$targetId, messageId: 123457))->assertOk();

        $this->assertNoAdminProof();
        $promptId = $this->forceReplyMessageId();
        Cache::flush();
        $photo = $this->photo(98006);
        $photo['message']['reply_to_message'] = ['message_id' => $promptId];
        $this->postTelegramWebhook($photo)->assertOk();

        if ($type === 'order') {
            $this->assertOrderProof($order, $payment);
            $this->assertNull($topUp->fresh()->payment_proof_submitted_at);
        } else {
            $this->assertTopUpProof($topUp);
            $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        }
        $this->assertCount(1, $this->adminPhotos());
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_payment_command_then_reply_stays_bound_after_cache_loss_and_a_newer_purchase(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $payment] = $this->manualOrder($customer);
        } else {
            $topUp = $this->manualTopUp($customer);
        }
        $this->postTelegramWebhook($this->messageUpdate(98007, '/payment'))->assertOk();
        $promptId = $this->forceReplyMessageId();
        [, $newerPayment] = $this->manualOrder($customer);
        Cache::flush();
        $photo = $this->photo(98008);
        $photo['message']['reply_to_message'] = ['message_id' => $promptId];

        $this->postTelegramWebhook($photo)->assertOk();

        $this->assertNull($newerPayment->fresh()->payment_proof_submitted_at);
        if ($type === 'order') {
            $this->assertOrderProof($order, $payment);
        } else {
            $this->assertTopUpProof($topUp);
        }
        $this->assertCount(1, $this->adminPhotos());
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_legacy_payment_command_can_reply_to_a_buyers_photo(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $payment] = $this->manualOrder($customer);
            $reference = $order->invoice_number;
        } else {
            $topUp = $this->manualTopUp($customer);
            $reference = 'topup:'.$topUp->id;
        }
        $command = $this->messageUpdate(98009, '/payment '.$reference);
        $command['message']['reply_to_message'] = $this->photo(98000)['message'];

        $this->postTelegramWebhook($command)->assertOk();

        if ($type === 'order') {
            $this->assertOrderProof($order, $payment);
        } else {
            $this->assertTopUpProof($topUp);
        }
        $this->assertCount(1, $this->adminPhotos());
        $this->assertSame('large-photo', $this->telegramRequestField($this->adminPhotos()->sole()[0], 'photo'));
    }

    public function test_reply_to_an_old_checkout_instruction_selects_that_order_not_a_newer_order(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        [$order, $payment] = $this->manualOrder($customer);
        $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $payment);
        $effect->forceFill(['state' => TelegramOutboundEffectState::Sent, 'sent_at' => now(), 'telegram_message_id' => '70001'])->save();
        [, $newerPayment] = $this->manualOrder($customer);
        $photo = $this->photo(98010);
        $photo['message']['reply_to_message'] = ['message_id' => 70001];

        $this->postTelegramWebhook($photo)->assertOk();

        $this->assertOrderProof($order, $payment);
        $this->assertNull($newerPayment->fresh()->payment_proof_submitted_at);
        $this->assertAdminProof('large-photo', 'admin:approve-payment:'.$order->id, 'admin:reject-payment:'.$order->id);
    }

    public function test_reply_to_a_top_up_canonical_message_does_not_use_a_newer_top_up(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $topUp = $this->manualTopUp($customer, 70002);
        $newer = $this->manualTopUp($customer, 70003);
        $photo = $this->photo(98011);
        $photo['message']['reply_to_message'] = ['message_id' => 70002];

        $this->postTelegramWebhook($photo)->assertOk();

        $this->assertTopUpProof($topUp);
        $this->assertNull($newer->fresh()->payment_proof_submitted_at);
        $this->assertAdminProof('large-photo', 'admin:topup-approve:'.$topUp->id, 'admin:topup-reject:'.$topUp->id);
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_duplicate_and_replayed_proof_preserve_the_original_without_forwarding_twice(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $target] = $this->manualOrder($customer);
            $reference = $order->invoice_number;
        } else {
            $target = $this->manualTopUp($customer);
            $reference = 'topup:'.$target->id;
        }
        $original = $this->photo(98012, '/payment '.$reference);
        $this->postTelegramWebhook($original)->assertOk();
        $submittedAt = $target->fresh()->payment_proof_submitted_at;
        $this->travel(1)->minutes();
        $replacement = $this->photo(98013, '/payment '.$reference);
        $replacement['message']['photo'][1]['file_id'] = 'replacement-photo';

        $this->postTelegramWebhook($replacement)->assertOk();
        $this->postTelegramWebhook($original)->assertOk();

        $this->assertSame('large-photo', $target->fresh()->payment_proof_file_id);
        $this->assertTrue($target->fresh()->payment_proof_submitted_at->equalTo($submittedAt));
        $this->assertCount(1, $this->adminPhotos());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_foreign_reference_chooser_and_prompt_cannot_attach_proof_to_another_customer(string $type): void
    {
        $owner = Customer::factory()->create(['telegram_id' => 54321]);
        $attacker = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $target] = $this->manualOrder($owner);
            $reference = $order->invoice_number;
            $targetId = $order->id;
        } else {
            $target = $this->manualTopUp($owner);
            $reference = 'topup:'.$target->id;
            $targetId = $target->id;
        }
        [, $ownPayment] = $this->manualOrder($attacker);
        $this->postTelegramWebhook($this->messageUpdate(98014, '/payment '.$reference, chatId: 54321))->assertOk();
        $promptId = $this->forceReplyMessageId('54321');

        $this->postTelegramWebhook($this->photo(98015, '/payment '.$reference))->assertOk();
        $this->postTelegramWebhook($this->callbackUpdate(98016, 'foreign-proof', 'proof:'.$type.':'.$targetId))->assertOk();
        $reply = $this->photo(98017);
        $reply['message']['reply_to_message'] = ['message_id' => $promptId];
        $this->postTelegramWebhook($reply)->assertOk();

        $this->assertNull($target->fresh()->payment_proof_submitted_at);
        $this->assertNull($ownPayment->fresh()->payment_proof_submitted_at);
        $this->assertNoAdminProof();
    }

    #[TestWith(['group'])]
    #[TestWith(['supergroup'])]
    public function test_group_proof_and_chooser_do_not_mutate_a_private_purchase(string $chatType): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        [$order, $payment] = $this->manualOrder($customer);
        $topUp = $this->manualTopUp($customer);
        foreach ([$order->invoice_number, 'topup:'.$topUp->id] as $index => $reference) {
            $photo = $this->photo(98020 + $index, '/payment '.$reference);
            $photo['message']['chat'] = ['id' => -100123, 'type' => $chatType];
            $this->postTelegramWebhook($photo)->assertOk();
        }
        $this->postTelegramWebhook($this->callbackUpdate(98022, 'group-proof', 'proof:order:'.$order->id, ['id' => 12345], -100123, $chatType))->assertOk();

        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertNull($topUp->fresh()->payment_proof_submitted_at);
        $this->assertNoAdminProof();
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_expired_reference_and_previously_bound_prompt_do_not_accept_proof(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $target] = $this->manualOrder($customer);
            $reference = $order->invoice_number;
        } else {
            $target = $this->manualTopUp($customer);
            $reference = 'topup:'.$target->id;
        }
        $this->postTelegramWebhook($this->messageUpdate(98023, '/payment '.$reference))->assertOk();
        $promptId = $this->forceReplyMessageId();
        $this->travel(2)->hours();
        $reply = $this->photo(98024);
        $reply['message']['reply_to_message'] = ['message_id' => $promptId];

        $this->postTelegramWebhook($reply)->assertOk();
        $this->postTelegramWebhook($this->photo(98025, '/payment '.$reference))->assertOk();

        $this->assertNull($target->fresh()->payment_proof_submitted_at);
        $this->assertNull($target->fresh()->payment_proof_file_id);
        $this->assertNoAdminProof();
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_unrecognized_reply_never_falls_back_to_the_only_pending_order(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        [, $payment] = $this->manualOrder($customer);
        $photo = $this->photo(98026);
        $photo['message']['reply_to_message'] = ['message_id' => 70099];

        $this->postTelegramWebhook($photo)->assertOk();

        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertNoAdminProof();
    }

    public function test_saved_top_up_proof_is_recovered_after_materialization_was_interrupted(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $topUp = $this->manualTopUp($customer);
        $topUp->forceFill([
            'payment_proof_file_id' => 'large-photo',
            'payment_proof_submitted_at' => now(),
            'payment_proof_admin_chat_id' => '999',
        ])->save();

        app(StoreBalanceTopUpOutbound::class)->recoverMissing();

        $this->assertNotNull($topUp->fresh()->payment_proof_forwarded_at);
        $this->assertAdminProof('large-photo', 'admin:topup-approve:'.$topUp->id, 'admin:topup-reject:'.$topUp->id);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_pending_top_up_proof_is_dispatched_by_recovery_after_queue_wakeup_was_lost(): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $topUp = $this->manualTopUp($customer);
        $topUp->forceFill([
            'payment_proof_file_id' => 'large-photo',
            'payment_proof_submitted_at' => now(),
            'payment_proof_admin_chat_id' => '999',
        ])->save();
        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpPaymentProof($topUp);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNotNull($topUp->fresh()->payment_proof_forwarded_at);
        $this->assertCount(1, $this->adminPhotos());
    }

    #[TestWith(['order'])]
    #[TestWith(['topup'])]
    public function test_payment_reply_to_bot_qr_never_submits_the_qr_itself_as_proof(string $type): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $target] = $this->manualOrder($customer);
            $effect = app(TelegramOutboundEffectService::class)->recordCheckoutInstructions($order, $target);
            $effect->forceFill(['state' => TelegramOutboundEffectState::Sent, 'sent_at' => now(), 'telegram_message_id' => '70001'])->save();
            $reference = $order->invoice_number;
        } else {
            $target = $this->manualTopUp($customer, 70001);
            $reference = 'topup:'.$target->id;
        }
        $command = $this->messageUpdate(98031, '/payment '.$reference);
        $command['message']['reply_to_message'] = [
            'message_id' => 70001, 'from' => ['is_bot' => true],
            'photo' => [['file_id' => 'merchant-qr']],
        ];

        $this->postTelegramWebhook($command)->assertOk();

        $this->assertNull($target->fresh()->payment_proof_file_id);
        $this->assertNoAdminProof();
        $this->forceReplyMessageId();
    }

    #[TestWith(['@owner', 'order'])]
    #[TestWith(['-10012345', 'order'])]
    #[TestWith(['@owner', 'topup'])]
    #[TestWith(['-10012345', 'topup'])]
    public function test_invalid_admin_target_does_not_claim_the_proof(string $admin, string $type): void
    {
        ManualPaymentSetting::query()->firstOrFail()->update(['admin_telegram_chat_id' => $admin]);
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        if ($type === 'order') {
            [$order, $target] = $this->manualOrder($customer);
            $reference = $order->invoice_number;
        } else {
            $target = $this->manualTopUp($customer);
            $reference = 'topup:'.$target->id;
        }

        $this->postTelegramWebhook($this->photo(98032, '/payment '.$reference))->assertOk();

        $this->assertNull($target->fresh()->payment_proof_submitted_at);
        $this->assertNull($target->fresh()->payment_proof_file_id);
        $this->assertNoAdminProof();
    }

    #[TestWith(['id'])]
    #[TestWith(['en'])]
    #[TestWith(['ms'])]
    public function test_bot_qualified_payment_caption_remains_supported(string $locale): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => $locale]);
        [$order, $payment] = $this->manualOrder($customer);

        $this->postTelegramWebhook($this->photo(98033, '/payment@LhieStoreTestBot '.$order->invoice_number))->assertOk();

        $this->assertOrderProof($order, $payment);
        $this->assertCount(1, $this->adminPhotos());
    }

    #[TestWith(['unknown', 1])]
    #[TestWith(['failed', 3])]
    public function test_recovery_never_blindly_resends_ambiguous_or_exhausted_top_up_proofs(string $state, int $attempts): void
    {
        $customer = Customer::factory()->create(['telegram_id' => 12345]);
        $topUp = $this->manualTopUp($customer);
        $topUp->forceFill([
            'payment_proof_file_id' => 'large-photo',
            'payment_proof_submitted_at' => now(),
            'payment_proof_admin_chat_id' => '999',
        ])->save();
        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpPaymentProof($topUp);
        $effect->forceFill(['state' => $state, 'attempts' => $attempts, 'next_attempt_at' => now()->subMinute()])->save();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])->assertSuccessful();

        $this->assertNoAdminProof();
        $this->assertSame($state, $effect->fresh()->state->value);
        $this->assertNull($topUp->fresh()->payment_proof_forwarded_at);
        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $topUp->fresh()->status);
    }

    private function manualOrder(Customer $customer): array
    {
        $order = Order::factory()->create(['customer_id' => $customer->id]);
        $payment = Payment::factory()->create(['order_id' => $order->id, 'provider' => 'manual', 'method' => 'QR_MANUAL']);

        return [$order, $payment];
    }

    private function manualTopUp(Customer $customer, int $messageId = 70000): StoreBalanceTopUp
    {
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => $messageId,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => str_pad((string) StoreBalanceFundingIntent::query()->count(), 32, '0', STR_PAD_LEFT),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => now()->addMinutes(15),
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'chat_id' => (string) $customer->telegram_id,
            'canonical_message_id' => $messageId,
            'locale' => $customer->locale,
            'provider' => 'manual',
            'merchant_ref' => 'BAL-PROOF-'.$intent->id,
            'idempotency_key' => 'balance-top-up:intent:'.$intent->id,
            'currency' => 'IDR',
            'amount' => 5000,
            'status' => StoreBalanceTopUpStatus::AwaitingConfirmation,
            'provider_payment_method' => 'QR_MANUAL',
            'provider_fee' => 0,
            'provider_total_payment' => 5000,
            'provider_create_state' => 'manual_pending',
            'expires_at' => now()->addMinutes(15),
        ]);
    }

    private function photo(int $id, ?string $caption = null): array
    {
        $update = $this->paymentPhotoUpdate($id, '');
        $update['message']['chat']['type'] = 'private';
        if ($caption === null) {
            unset($update['message']['caption']);
        } else {
            $update['message']['caption'] = $caption;
        }

        return $update;
    }

    private function forceReplyMessageId(string $chatId = '12345'): int
    {
        $prompts = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage')
            && (string) $request['chat_id'] === $chatId
            && ($request['reply_markup']['force_reply'] ?? false) === true);
        $this->assertCount(1, $prompts);

        return (int) $prompts->sole()[1]->json('result.message_id');
    }

    private function assertOrderProof(Order $order, Payment $payment): void
    {
        $payment->refresh();
        $this->assertSame('large-photo', $payment->payment_proof_file_id);
        $this->assertNotNull($payment->payment_proof_submitted_at);
        $this->assertNotNull($payment->payment_proof_forwarded_at);
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->payment_status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    private function assertTopUpProof(StoreBalanceTopUp $topUp): void
    {
        $topUp->refresh();
        $this->assertSame('large-photo', $topUp->payment_proof_file_id);
        $this->assertNotNull($topUp->payment_proof_submitted_at);
        $this->assertNotNull($topUp->payment_proof_forwarded_at);
        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $topUp->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    private function adminPhotos(): Collection
    {
        return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === '999');
    }

    private function assertNoAdminProof(): void
    {
        $this->assertCount(0, $this->adminPhotos());
    }

    private function assertAdminProof(string $fileId, string $approve, string $reject): void
    {
        $photos = $this->adminPhotos();
        $this->assertCount(1, $photos);
        $request = $photos->sole()[0];
        $this->assertSame($fileId, $this->telegramRequestField($request, 'photo'));
        $keyboard = $this->telegramRequestField($request, 'reply_markup');
        $callbacks = collect($keyboard['inline_keyboard'])->flatten(1)->pluck('callback_data')->all();
        $this->assertContains($approve, $callbacks);
        $this->assertContains($reject, $callbacks);
    }
}
