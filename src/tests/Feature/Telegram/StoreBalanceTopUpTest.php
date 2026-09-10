<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendStoreBalanceTopUpInstructions;
use App\Jobs\SendStoreBalanceTopUpReceipt;
use App\Models\Customer;
use App\Models\CustomerBalanceAccount;
use App\Models\Order;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\TelegramNavigationRootService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpTest extends TelegramBotTestSupport
{
    private const QRIS_URL = 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png';

    private const PNG_BYTES = "\x89PNG\r\n\x1A\nstore-balance-top-up";

    public function test_confirmation_creates_one_payable_top_up_and_settles_requested_credit_after_provider_success(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        $customer = Customer::factory()->create(['telegram_id' => 46201, 'locale' => 'en']);
        $root = $this->openFundingChooser(46201, 46201);
        $this->postTelegramUpdate($this->messageUpdate(46203, '/deposit 5000', [], 46201));
        $intent = StoreBalanceFundingIntent::query()->sole();

        $this->postTelegramUpdate($this->callbackUpdate(
            46204,
            'top-up-confirm',
            'balance:confirm:'.$intent->nonce,
            [],
            46201,
            'private',
            $root->message_id,
        ));

        $topUp = StoreBalanceTopUp::query()->sole();
        $this->assertSame(StoreBalanceFundingIntentStatus::Confirmed, $intent->fresh()->status);
        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->status);
        $this->assertSame($customer->id, $topUp->customer_id);
        $this->assertSame('46201', $topUp->chat_id);
        $this->assertSame(5000, $topUp->amount);
        $this->assertSame(16, $topUp->provider_fee);
        $this->assertSame(5016, $topUp->provider_total_payment);
        $this->assertSame(self::QRIS_URL, $topUp->provider_qris_url);
        $safePayload = json_encode($topUp->provider_payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::QRIS_URL, $safePayload);
        $this->assertStringNotContainsString('top-up-signature', $safePayload);
        $this->assertSame(1, $createCalls);
        $this->assertSame(0, $statusCalls);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $providerEvent = StoreBalanceTopUpEvent::query()
            ->where('event_type', 'provider_create')
            ->sole();
        $this->assertStringNotContainsString(self::QRIS_URL, json_encode($providerEvent->raw_payload, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('signature', $providerEvent->raw_payload['data'] ?? $providerEvent->raw_payload);

        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP, $effect->effect_type);
        $this->assertSame(StoreBalanceTopUp::class, $effect->source_type);
        $this->assertSame((string) $topUp->id, $effect->source_id);
        $this->assertSame('pending', $effect->state->value);
        (new SendStoreBalanceTopUpInstructions((string) $topUp->id, effectId: (string) $effect->getKey()))->handle();
        $effect->refresh();
        $this->assertSame('sent', $effect->state->value);
        $photo = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertStringContainsString('Balance credit after payment confirmation: Rp5.000', $caption);
        $this->assertStringContainsString('Provider fee: Rp16', $caption);
        $this->assertStringContainsString('Final amount to pay: Rp5.016', $caption);
        $this->assertStringContainsString('not credited until KlikQRIS confirms', $caption);

        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => $topUp->merchant_ref,
            'amount' => 5000,
            'total_amount' => 5016,
            'status' => 'SUCCESS',
            'signature' => 'top-up-signature',
        ])->assertOk();

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, $statusCalls);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(5000, StoreBalanceEntry::query()->sole()->amount);
        $this->assertSame(StoreBalanceEntryKind::TopUpCredit, StoreBalanceEntry::query()->sole()->kind);
        $this->assertSame(1, $topUp->fresh()->creditEntry()->count());
        $callbackEvent = StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->sole();
        $this->assertNotNull($callbackEvent->processed_at);
        $this->assertSame(2, StoreBalanceTopUpEvent::query()->count());
        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => $topUp->merchant_ref,
            'amount' => 5000,
            'total_amount' => 5016,
            'status' => 'SUCCESS',
            'signature' => 'top-up-signature',
        ])->assertOk();
        $this->assertSame(1, $statusCalls);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->sole()->received_count);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());

        $receiptEffect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->sole();
        $this->assertSame('pending', $receiptEffect->state->value);
        (new SendStoreBalanceTopUpReceipt((string) $topUp->id, effectId: (string) $receiptEffect->getKey()))->handle();
        $receiptEffect->refresh();
        $this->assertSame('sent', $receiptEffect->state->value);
        $receiptMessages = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertNotEmpty($receiptMessages);
        $receiptMessage = $receiptMessages->last()[0];
        $receiptText = (string) $this->telegramRequestField($receiptMessage, 'text');
        $this->assertStringContainsString('Store Balance top-up paid', $receiptText);
        $this->assertStringContainsString('Credited to Store Balance: Rp 5.000', $receiptText);
        $this->assertStringContainsString('KlikQRIS total paid: Rp 5.016', $receiptText);
        $this->assertStringContainsString($topUp->merchant_ref, $receiptText);
        $this->assertStringNotContainsString(self::QRIS_URL, $receiptText);
        $this->assertNotNull(app(TelegramNavigationRootService::class)->currentForChat($customer, (string) $customer->telegram_id));
        $this->assertNotNull(app(TelegramOutboundEffectService::class)->sentBalanceTopUpReceiptFor(
            $customer,
            (string) $customer->telegram_id,
            (int) $receiptEffect->telegram_message_id,
        ));

        $this->postTelegramUpdate($this->callbackUpdate(
            46205,
            'top-up-receipt-balance',
            'balance',
            [],
            46201,
            'private',
            $receiptEffect->telegram_message_id,
        ));
        $rootEdits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            || str_ends_with($request->url(), '/editMessageCaption'));
        $this->assertNotEmpty($rootEdits);
        $rootEdit = $rootEdits->last()[0];
        $this->assertSame((string) $root->message_id, (string) $this->telegramRequestField($rootEdit, 'message_id'));
        $this->postTelegramUpdate($this->callbackUpdate(
            46207,
            'top-up-receipt-home',
            'menu:home',
            [],
            46201,
            'private',
            $receiptEffect->telegram_message_id,
        ));
        $homeEdits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            || str_ends_with($request->url(), '/editMessageCaption'));
        $this->assertSame((string) $root->message_id, (string) $homeEdits->last()[0]->data()['message_id']);
        $this->postTelegramUpdate($this->callbackUpdate(
            46208,
            'top-up-receipt-support',
            'menu:support',
            [],
            46201,
            'private',
            $receiptEffect->telegram_message_id,
        ));
        $supportEdits = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            || str_ends_with($request->url(), '/editMessageCaption'));
        $this->assertSame((string) $root->message_id, (string) $supportEdits->last()[0]->data()['message_id']);

        $this->postTelegramUpdate($this->callbackUpdate(
            46206,
            'top-up-check',
            StoreBalanceTopUpService::actionCallback('check', $topUp->getKey()),
            [],
            46201,
            'private',
            $effect->telegram_message_id,
        ));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
    }

    public function test_paid_callback_after_reconciliation_is_processed_without_recrediting(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46241, 'locale' => 'en']);
        $root = $this->openFundingChooser(46241, 46241);
        $this->postTelegramUpdate($this->messageUpdate(46243, '/deposit 5000', [], 46241));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46244,
            'top-up-confirm-reconciliation',
            'balance:confirm:'.$intent->nonce,
            [],
            46241,
            'private',
            $root->message_id,
        ));
        $topUp = StoreBalanceTopUp::query()->sole();

        app(StoreBalanceTopUpService::class)->reconcile($topUp);
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, $statusCalls);
        $this->assertSame(1, StoreBalanceEntry::query()->count());

        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => $topUp->merchant_ref,
            'amount' => 5000,
            'total_amount' => 5016,
            'status' => 'SUCCESS',
            'signature' => 'top-up-signature',
        ])->assertOk();

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, $statusCalls);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $callbackEvent = StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->sole();
        $this->assertNotNull($callbackEvent->processed_at);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    public function test_unauthenticated_paid_callback_is_recorded_without_crediting_balance(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46221, 'locale' => 'en']);
        $root = $this->openFundingChooser(46221, 46221);
        $this->postTelegramUpdate($this->messageUpdate(46223, '/deposit 5000', [], 46221));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46224,
            'top-up-confirm-auth',
            'balance:confirm:'.$intent->nonce,
            [],
            46221,
            'private',
            $root->message_id,
        ));
        $topUp = StoreBalanceTopUp::query()->sole();

        $this->postJson('/api/webhook/klikqris/sandbox', [
            'order_id' => $topUp->merchant_ref,
            'amount' => 5000,
            'total_amount' => 5016,
            'status' => 'SUCCESS',
            'signature' => 'wrong-signature',
        ])->assertOk();

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->fresh()->status);
        $this->assertSame(0, $statusCalls);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $event = StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->sole();
        $this->assertNull($event->processed_at);
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    public function test_failed_credit_rolls_back_callback_event_top_up_and_ledger_mutation(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        $customer = Customer::factory()->create(['telegram_id' => 46231, 'locale' => 'en']);
        $root = $this->openFundingChooser(46231, 46231);
        $this->postTelegramUpdate($this->messageUpdate(46233, '/deposit 5000', [], 46231));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46234,
            'top-up-confirm-rollback',
            'balance:confirm:'.$intent->nonce,
            [],
            46231,
            'private',
            $root->message_id,
        ));
        $topUp = StoreBalanceTopUp::query()->sole();
        $account = CustomerBalanceAccount::query()->create(['customer_id' => $customer->id]);
        $account->forceFill(['balance' => 1])->save();

        $exception = null;
        try {
            $this->withoutExceptionHandling()->postJson('/api/webhook/klikqris/sandbox', [
                'order_id' => $topUp->merchant_ref,
                'amount' => 5000,
                'total_amount' => 5016,
                'status' => 'SUCCESS',
                'signature' => 'top-up-signature',
            ]);
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertSame(StoreBalanceTopUpStatus::RecoveryPending, $topUp->fresh()->status);
        $this->assertSame(1, (int) CustomerBalanceAccount::query()->whereKey($account->id)->value('balance'));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, StoreBalanceTopUpEvent::query()->where('event_type', 'callback')->count());
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    public function test_replayed_confirmation_reuses_the_same_top_up_reference_and_instruction_effect(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46211, 'locale' => 'en']);
        $root = $this->openFundingChooser(46211, 46211);
        $this->postTelegramUpdate($this->messageUpdate(46213, '/deposit 5000', [], 46211));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $callback = 'balance:confirm:'.$intent->nonce;

        $this->postTelegramUpdate($this->callbackUpdate(46214, 'top-up-confirm-1', $callback, [], 46211, 'private', $root->message_id));
        $topUp = StoreBalanceTopUp::query()->sole();
        $merchantRef = $topUp->merchant_ref;
        $effect = TelegramOutboundEffect::query()->sole();
        (new SendStoreBalanceTopUpInstructions((string) $topUp->id, effectId: (string) $effect->getKey()))->handle();

        $this->postTelegramUpdate($this->callbackUpdate(46215, 'top-up-confirm-2', $callback, [], 46211, 'private', $root->message_id));

        $this->assertSame(1, StoreBalanceTopUp::query()->count());
        $this->assertSame($merchantRef, StoreBalanceTopUp::query()->sole()->merchant_ref);
        $this->assertSame(1, $createCalls);
        $this->assertSame(0, $statusCalls);
        $topUpPhotos = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto')
            && str_contains((string) $this->telegramRequestField($request, 'caption'), 'Balance credit after payment confirmation'));
        $this->assertCount(1, $topUpPhotos);
        $this->assertSame('sent', TelegramOutboundEffect::query()->sole()->state->value);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    private function postTelegramUpdate(array $payload): void
    {
        $this->postTelegramWebhook($payload)->assertOk();
        $update = TelegramUpdate::query()->where('update_id', $payload['update_id'])->sole();
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));
    }

    private function openFundingChooser(int $chatId, int $updateId): TelegramNavigationRoot
    {
        $this->postTelegramUpdate($this->messageUpdate($updateId, '/start', [], $chatId));
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            $updateId + 1,
            'funding-open-'.$chatId,
            'balance:add',
            [],
            $chatId,
            'private',
            $root->message_id,
        ));

        return $root->fresh();
    }

    private function fakeProvider(int &$createCalls, int &$statusCalls): void
    {
        $this->fakeTelegramHttp([
            'https://klikqris.com/api/sandbox/qris/create' => function (Request $request) use (&$createCalls) {
                $createCalls++;

                return Http::response(['data' => [
                    'order_id' => $request['order_id'],
                    'amount' => 5000,
                    'total_amount' => 5016,
                    'fee' => 16,
                    'status' => 'PENDING',
                    'qris_url' => self::QRIS_URL,
                    'qr_url' => self::QRIS_URL,
                    'signature' => 'top-up-signature',
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]]);
            },
            'https://klikqris.com/api/sandbox/qris/status/*' => function (Request $request) use (&$statusCalls) {
                $statusCalls++;

                return Http::response(['data' => [
                    'order_id' => basename($request->url()),
                    'amount' => 5000,
                    'total_amount' => 5016,
                    'status' => 'SUCCESS',
                    'signature' => 'top-up-signature',
                    'paid_at' => now()->toISOString(),
                    'expired_at' => now()->addMinutes(15)->toISOString(),
                ]]);
            },
            self::QRIS_URL => Http::response(self::PNG_BYTES, 200, ['Content-Type' => 'image/png']),
        ]);
    }
}
