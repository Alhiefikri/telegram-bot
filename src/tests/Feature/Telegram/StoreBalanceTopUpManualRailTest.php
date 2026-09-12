<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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

    public function test_manual_confirmation_sends_static_qr_and_creates_one_admin_review(): void
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
        $this->assertStringContainsString('An admin will verify the payment.', $caption);

        $review = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_MANUAL_REVIEW)
            ->sole();
        $this->assertSame(self::ADMIN_CHAT_ID, (int) $review->telegram_chat_id);
        $this->assertSame('sent', $review->state->value);

        $adminMessage = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'))->last()[0];
        $this->assertSame(self::ADMIN_CHAT_ID, (int) $this->telegramRequestField($adminMessage, 'chat_id'));
        $keyboard = $this->telegramRequestField($adminMessage, 'reply_markup');
        $this->assertSame('admin:topup-approve:'.$topUp->getKey(), $keyboard['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('admin:topup-reject:'.$topUp->getKey(), $keyboard['inline_keyboard'][0][1]['callback_data']);
    }

    public function test_admin_approval_credits_the_balance_exactly_once_and_sends_the_receipt(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46614, 46615);
        $messageId = (int) TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_MANUAL_REVIEW)
            ->sole()
            ->telegram_message_id;

        $this->postTelegramUpdate($this->callbackUpdate(
            46616,
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
            46617,
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

    public function test_admin_rejection_never_credits_the_balance(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        Customer::factory()->create(['telegram_id' => self::ADMIN_CHAT_ID, 'locale' => 'id']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46624, 46625);
        $messageId = (int) TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_MANUAL_REVIEW)
            ->sole()
            ->telegram_message_id;

        $this->postTelegramUpdate($this->callbackUpdate(
            46626,
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
        $topUp = $this->confirmTopUp($root, 46634, 46635);

        $this->postTelegramUpdate($this->callbackUpdate(
            46636,
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

    public function test_due_manual_top_up_expires_without_crediting(): void
    {
        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);

        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46644, 46645);
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
            46601 + 1,
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
