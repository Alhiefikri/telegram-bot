<?php

namespace Tests\Feature\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpManualRailTest extends TelegramBotTestSupport
{
    private const ADMIN_CHAT_ID = 990001;

    private const BUYER_CHAT_ID = 46601;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-28 10:10:00 UTC');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'manual',
            'is_active' => true,
            'admin_telegram_chat_id' => (string) self::ADMIN_CHAT_ID,
            'qris_payment_ttl_minutes' => 10,
        ]);
    }

    public function test_manual_confirmation_sends_static_qr_and_asks_for_a_payment_proof(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46604, 46605);

        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $topUp->status);
        $this->assertSame('manual', $topUp->provider);
        $this->assertSame('QR_MANUAL', $topUp->provider_payment_method);
        $this->assertSame(5000, (int) $topUp->provider_total_payment);
        $this->assertSame(0, (int) $topUp->provider_fee);

        $presentation = app(StoreBalanceTopUpService::class)->presentation($topUp);
        $this->assertTrue($presentation->isAwaitingConfirmation());
        $payable = $presentation->payable();
        $this->assertNotNull($payable);
        $this->assertSame(
            file_get_contents(public_path('qr-lhie.jpg')),
            app(StoreBalanceTopUpService::class)->instructionAttachment($topUp, $payable)->photoContents(),
        );

        $instruction = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->sole();
        $this->assertSame('balance-top-up:'.$topUp->getKey().':manual-instructions:v1', $instruction->effect_key);
        $this->assertSame('sent', $instruction->state->value);

        $photo = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertStringContainsString('Balance credit after payment confirmation: Rp5.000', $caption);
        $this->assertStringContainsString('Send the payment proof photo to this chat with the /payment caption.', $caption);

        // No admin effect exists before the customer sends a proof: the proof
        // itself is the review artifact, exactly like a manual product purchase.
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
            ->count());
    }

    public function test_payment_proof_is_forwarded_to_the_admin_with_decision_buttons(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46612, 46613);

        $this->postTelegramUpdate($this->paymentPhotoUpdate(46616, '', [], self::BUYER_CHAT_ID));

        $fresh = $topUp->fresh();
        $this->assertSame('large-photo', (string) $fresh->payment_proof_file_id);
        $this->assertNotNull($fresh->payment_proof_submitted_at);
        $this->assertNotNull($fresh->payment_proof_forwarded_at);
        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $fresh->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());

        $proof = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
            ->sole();
        $this->assertSame('sent', $proof->state->value);
        $this->assertSame(self::ADMIN_CHAT_ID, (int) $proof->telegram_chat_id);
        $this->assertSame('balance-top-up:'.$topUp->getKey().':admin-payment-proof:v1', $proof->effect_key);

        $adminPhoto = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && (string) $this->telegramRequestField($request, 'chat_id') === (string) self::ADMIN_CHAT_ID)->last()[0];
        $this->assertSame('large-photo', (string) $this->telegramRequestField($adminPhoto, 'photo'));
        $keyboard = $this->telegramRequestField($adminPhoto, 'reply_markup');
        $this->assertSame('admin:topup-approve:'.$topUp->getKey(), $keyboard['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('admin:topup-reject:'.$topUp->getKey(), $keyboard['inline_keyboard'][0][1]['callback_data']);

        // A second proof for the same top-up is refused without a second effect.
        $this->postTelegramUpdate($this->paymentPhotoUpdate(46617, '', [], self::BUYER_CHAT_ID));
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
            ->count());
    }

    public function test_manual_top_up_proof_and_approval_never_settle_a_same_amount_pending_order(): void
    {
        $customer = Customer::factory()->create([
            'telegram_id' => self::BUYER_CHAT_ID,
            'locale' => 'en',
        ]);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'price_amount' => 5000,
        ]);
        $cart = Cart::factory()->create([
            'customer_id' => $customer->id,
            'expires_at' => now()->addHour(),
        ]);
        CartItem::factory()->create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]);
        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::manualQris(),
        );
        $payment = $order->payments()->sole();

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46592, 46593);
        $this->postTelegramUpdate($this->paymentPhotoUpdate(46594, '', [], self::BUYER_CHAT_ID));

        $this->assertNotNull($topUp->fresh()->payment_proof_submitted_at);
        $this->assertNull($payment->fresh()->payment_proof_submitted_at);
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());

        $proof = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
            ->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46595,
            'topup-isolation-approve',
            'admin:topup-approve:'.$topUp->getKey(),
            [],
            self::ADMIN_CHAT_ID,
            'private',
            (int) $proof->telegram_message_id,
        ));

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(5000, CustomerBalanceAccount::query()
            ->where('customer_id', $customer->id)
            ->sole()
            ->balance);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(PaymentStatus::Pending, $payment->fresh()->status);
        $this->assertSame(OrderStatus::PendingPayment, $order->fresh()->order_status);
        $this->assertSame(0, Payment::query()->where('status', PaymentStatus::Paid)->count());
    }

    public function test_admin_approval_credits_the_balance_exactly_once_and_sends_the_receipt(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46622, 46623);
        $this->postTelegramUpdate($this->paymentPhotoUpdate(46626, '', [], self::BUYER_CHAT_ID));
        $messageId = (int) TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
            ->sole()
            ->telegram_message_id;

        $this->postTelegramUpdate($this->callbackUpdate(
            46627,
            'topup-approve',
            'admin:topup-approve:'.$topUp->getKey(),
            [],
            self::ADMIN_CHAT_ID,
            'private',
            $messageId,
        ));

        $fresh = $topUp->fresh();
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $fresh->status);
        $this->assertSame('manual_confirmed', $fresh->provider_create_state);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(5000, (int) StoreBalanceEntry::query()->sole()->amount);

        $receipt = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->sole();
        $this->assertSame('sent', $receipt->state->value);
        $this->assertSame('balance-top-up:'.$topUp->getKey().':manual-receipt:v1', $receipt->effect_key);

        $this->postTelegramUpdate($this->callbackUpdate(
            46628,
            'topup-approve-again',
            'admin:topup-approve:'.$topUp->getKey(),
            [],
            self::ADMIN_CHAT_ID,
            'private',
            $messageId,
        ));

        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_same_admin_can_confirm_same_amount_top_ups_without_reusing_another_top_up_event(bool $legacyEventKey): void
    {
        $customer = Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);
        $root = $this->openFundingChooser();
        $topUps = [];

        foreach ([46700, 46710] as $updateId) {
            $topUp = $this->confirmTopUp($root->fresh(), $updateId, $updateId + 1);
            $topUps[] = $topUp;
            $this->postTelegramUpdate($this->paymentPhotoUpdate($updateId + 2, '', [], self::BUYER_CHAT_ID));
            $proof = TelegramOutboundEffect::query()
                ->where('effect_key', 'balance-top-up:'.$topUp->getKey().':admin-payment-proof:v1')
                ->sole();

            $this->postTelegramUpdate($this->callbackUpdate(
                $updateId + 3,
                'same-amount-approve-'.$updateId,
                'admin:topup-approve:'.$topUp->getKey(),
                [],
                self::ADMIN_CHAT_ID,
                'private',
                (int) $proof->telegram_message_id,
            ));

            $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
            $entry = StoreBalanceEntry::query()
                ->where('source_type', 'balance_top_up')
                ->where('source_id', (string) $topUp->getKey())
                ->sole();
            $this->assertSame($customer->id, (int) $entry->account->customer_id);
            $this->assertSame(5000, (int) $entry->amount);
            $event = StoreBalanceTopUpEvent::query()
                ->where('store_balance_top_up_id', $topUp->getKey())
                ->where('event_type', 'manual_confirmation')
                ->sole();
            $this->assertNotNull($event->processed_at);
            if ($legacyEventKey && count($topUps) === 1) {
                $event->forceFill([
                    'idempotency_key' => hash('sha256', 'manual_confirmation:'.json_encode(
                        $event->raw_payload,
                        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                    )),
                ])->save();
            }
            $receipt = TelegramOutboundEffect::query()
                ->where('effect_key', 'balance-top-up:'.$topUp->getKey().':manual-receipt:v1')
                ->sole();
            $this->assertSame('sent', $receipt->state->value);
        }

        $this->assertNotSame($topUps[0]->getKey(), $topUps[1]->getKey());
        foreach ($topUps as $index => $topUp) {
            $this->postTelegramUpdate($this->callbackUpdate(
                46720 + $index,
                'same-amount-replay-'.$index,
                'admin:topup-approve:'.$topUp->getKey(),
                [],
                self::ADMIN_CHAT_ID,
                'private',
                123456,
            ));
        }

        $this->assertSame(10000, (int) CustomerBalanceAccount::query()->where('customer_id', $customer->id)->sole()->balance);
        $this->assertSame(2, StoreBalanceEntry::query()->count());
        $this->assertSame(2, StoreBalanceTopUpEvent::query()->where('event_type', 'manual_confirmation')->count());
        $this->assertSame(2, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    public function test_admin_rejection_never_credits_the_balance(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46632, 46633);
        $this->postTelegramUpdate($this->paymentPhotoUpdate(46636, '', [], self::BUYER_CHAT_ID));
        $messageId = (int) TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)
            ->sole()
            ->telegram_message_id;

        $this->postTelegramUpdate($this->callbackUpdate(
            46637,
            'topup-reject',
            'admin:topup-reject:'.$topUp->getKey(),
            [],
            self::ADMIN_CHAT_ID,
            'private',
            $messageId,
        ));

        $fresh = $topUp->fresh();
        $this->assertSame(StoreBalanceTopUpStatus::Failed, $fresh->status);
        $this->assertSame('manual_rejected', $fresh->provider_create_state);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertTrue(app(StoreBalanceTopUpService::class)->presentation($fresh)->isRejected());
    }

    public function test_non_admin_cannot_decide_a_manual_top_up(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46642, 46643);
        $this->postTelegramUpdate($this->paymentPhotoUpdate(46646, '', [], self::BUYER_CHAT_ID));

        $this->postTelegramUpdate($this->callbackUpdate(
            46647,
            'topup-approve-buyer',
            'admin:topup-approve:'.$topUp->getKey(),
            [],
            self::BUYER_CHAT_ID,
            'private',
            null,
        ));

        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_admin_approval_at_the_manual_deadline_expires_without_a_credit_or_receipt(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);
        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46730, 46731);
        $topUp->forceFill(['expires_at' => now()])->save();

        $this->postTelegramUpdate($this->callbackUpdate(
            46732,
            'expired-topup-approve',
            'admin:topup-approve:'.$topUp->getKey(),
            [],
            self::ADMIN_CHAT_ID,
            'private',
            123456,
        ));

        $this->assertSame(StoreBalanceTopUpStatus::Expired, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    public function test_due_manual_top_up_expires_without_crediting(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46652, 46653);
        $topUp->forceFill([
            'status' => StoreBalanceTopUpStatus::AwaitingConfirmation,
            'expires_at' => now()->subSecond(),
        ])->save();

        $this->artisan('store-balance:expire-top-ups --chunk=10')
            ->expectsOutputToContain('expired=1')
            ->assertSuccessful();

        $this->assertSame(StoreBalanceTopUpStatus::Expired, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    private function openFundingChooser(): TelegramNavigationRoot
    {
        $this->postTelegramUpdate($this->messageUpdate(46601, '/start', [], self::BUYER_CHAT_ID));
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46602,
            'funding-open',
            'balance:add',
            [],
            self::BUYER_CHAT_ID,
            'private',
            $root->message_id,
        ));

        return $root->fresh();
    }

    private function confirmTopUp(TelegramNavigationRoot $root, int $depositUpdateId, int $confirmUpdateId): StoreBalanceTopUp
    {
        $this->postTelegramUpdate($this->messageUpdate($depositUpdateId, '/deposit 5000', [], self::BUYER_CHAT_ID));
        $intent = StoreBalanceFundingIntent::query()
            ->whereIn('status', [
                StoreBalanceFundingIntentStatus::Pending->value,
                StoreBalanceFundingIntentStatus::Confirmed->value,
            ])
            ->latest('id')
            ->firstOrFail();

        $this->postTelegramUpdate($this->callbackUpdate(
            $confirmUpdateId,
            'top-up-confirm-manual',
            'balance:confirm:'.$intent->nonce,
            [],
            self::BUYER_CHAT_ID,
            'private',
            $root->message_id,
        ));

        return StoreBalanceTopUp::query()->latest('id')->firstOrFail();
    }

    /** @param array<string, mixed> $payload */
    private function postTelegramUpdate(array $payload): void
    {
        $this->postTelegramWebhook($payload)->assertOk();
        $update = TelegramUpdate::query()->where('update_id', $payload['update_id'])->sole();
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
    }
}
