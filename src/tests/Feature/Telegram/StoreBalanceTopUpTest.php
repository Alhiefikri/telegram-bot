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
use App\Services\Telegram\StoreBalanceTopUpOutbound;
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
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
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
        $this->assertStringContainsString('not credited until the payment is confirmed', $caption);

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
        $this->assertStringContainsString('Total paid: Rp 5.016', $receiptText);
        $this->assertStringContainsString($topUp->merchant_ref, $receiptText);
        $this->assertStringNotContainsString(self::QRIS_URL, $receiptText);
        $this->assertNotNull(app(TelegramNavigationRootService::class)->currentForChat($customer, (string) $customer->telegram_id));
        $this->assertTrue(app(StoreBalanceTopUpOutbound::class)->hasSentReceiptFor(
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

    public function test_interactive_confirmation_and_scheduled_reconciliation_converge_on_one_effect_per_family(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46251, 'locale' => 'en']);
        $root = $this->openFundingChooser(46251, 46251);
        $this->postTelegramUpdate($this->messageUpdate(46253, '/deposit 5000', [], 46251));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46254,
            'top-up-confirm-convergence',
            'balance:confirm:'.$intent->nonce,
            [],
            46251,
            'private',
            $root->message_id,
        ));

        $topUp = StoreBalanceTopUp::query()->sole();
        $instruction = TelegramOutboundEffect::query()->sole();
        $instructionKey = $instruction->effect_key;
        $this->assertSame(
            'balance-top-up:'.$topUp->getKey().':klikqris-instructions:v1',
            $instructionKey,
        );

        $this->artisan('klikqris:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('recovered=1')
            ->assertSuccessful();

        $topUp->refresh();
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->status);
        $this->assertSame(1, $createCalls);
        $this->assertSame(1, $statusCalls);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->count());
        $this->assertSame(
            $instructionKey,
            TelegramOutboundEffect::query()
                ->where('source_id', (string) $topUp->getKey())
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
                ->sole()
                ->effect_key,
        );
        $receipt = TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->sole();
        $this->assertSame(
            'balance-top-up:'.$topUp->getKey().':klikqris-receipt:v1',
            $receipt->effect_key,
        );
    }

    public function test_paid_callback_after_reconciliation_is_processed_without_recrediting(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
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
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
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
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
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
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
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

    public function test_repeated_top_up_actions_reuse_one_instruction_effect_and_keep_pending_surface(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46261, 'locale' => 'en']);
        $root = $this->openFundingChooser(46261, 46261);
        $this->postTelegramUpdate($this->messageUpdate(46263, '/deposit 5000', [], 46261));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46264,
            'top-up-confirm-action',
            'balance:confirm:'.$intent->nonce,
            [],
            46261,
            'private',
            $root->message_id,
        ));

        $topUp = StoreBalanceTopUp::query()->sole();
        $callback = StoreBalanceTopUpService::actionCallback('retry', $topUp->getKey());
        $this->postTelegramUpdate($this->callbackUpdate(
            46265,
            'top-up-retry-1',
            $callback,
            [],
            46261,
            'private',
            $root->message_id,
        ));
        $this->postTelegramUpdate($this->callbackUpdate(
            46266,
            'top-up-retry-2',
            $callback,
            [],
            46261,
            'private',
            $root->message_id,
        ));

        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_id', (string) $topUp->getKey())
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->count());
        $surface = $this->editCopy($this->lastCanonicalEdit(46261));
        $this->assertStringContainsString('The QRIS will be sent in a separate message.', $surface);
        $this->assertStringNotContainsString('The QRIS was sent in a separate message.', $surface);
    }

    public function test_failed_top_up_instruction_dispatch_keeps_pending_surface(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46271, 'locale' => 'en']);
        $root = $this->openFundingChooser(46271, 46271);
        $this->postTelegramUpdate($this->messageUpdate(46273, '/deposit 5000', [], 46271));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46274,
            'top-up-confirm-failure',
            'balance:confirm:'.$intent->nonce,
            [],
            46271,
            'private',
            $root->message_id,
        ));

        $topUp = StoreBalanceTopUp::query()->sole();
        $payload = $this->callbackUpdate(
            46275,
            'top-up-retry-failure',
            StoreBalanceTopUpService::actionCallback('retry', $topUp->getKey()),
            [],
            46271,
            'private',
            $root->message_id,
        );
        $this->postTelegramWebhook($payload)->assertOk();
        $update = TelegramUpdate::query()->where('update_id', 46275)->sole();
        config()->set('queue.default', 'missing');
        (new ProcessTelegramUpdate($update->id))->handle(app(TelegramUpdateProcessor::class));

        $surface = $this->editCopy($this->lastCanonicalEdit(46271));
        $this->assertStringContainsString('The QRIS will be sent in a separate message.', $surface);
        $this->assertStringNotContainsString('The QRIS was sent in a separate message.', $surface);
        $this->assertSame('pending', TelegramOutboundEffect::query()->sole()->state->value);
    }

    public function test_invalid_lifecycle_does_not_create_instruction_effect_for_malformed_payable_quote(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();
        $createCalls = 0;
        $statusCalls = 0;
        $this->fakeProvider($createCalls, $statusCalls);

        Customer::factory()->create(['telegram_id' => 46281, 'locale' => 'en']);
        $root = $this->openFundingChooser(46281, 46281);
        $this->postTelegramUpdate($this->messageUpdate(46283, '/deposit 5000', [], 46281));
        $intent = StoreBalanceFundingIntent::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46284,
            'top-up-confirm-malformed',
            'balance:confirm:'.$intent->nonce,
            [],
            46281,
            'private',
            $root->message_id,
        ));

        $topUp = StoreBalanceTopUp::query()->sole();
        TelegramOutboundEffect::query()->sole()->delete();
        $payload = $topUp->provider_payload;
        $payload['data']['qris_url'] = 'https://malformed.example/qris.png';
        $topUp->forceFill(['provider_payload' => $payload])->save();

        $this->assertTrue($topUp->fresh()->isPayable());

        $this->postTelegramUpdate($this->callbackUpdate(
            46285,
            'top-up-retry-malformed',
            StoreBalanceTopUpService::actionCallback('retry', $topUp->getKey()),
            [],
            46281,
            'private',
            $root->message_id,
        ));

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->fresh()->status);
        $this->assertSame(0, TelegramOutboundEffect::query()->count());
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

    /** @return array<string, mixed> */
    private function lastCanonicalEdit(int $chatId): array
    {
        $edits = Http::recorded(fn (Request $request): bool => (
            str_ends_with($request->url(), '/editMessageCaption')
            || str_ends_with($request->url(), '/editMessageText')
        ) && (string) ($request['chat_id'] ?? '') === (string) $chatId);
        $this->assertNotEmpty($edits, "Expected a canonical edit for chat {$chatId}.");

        return $edits->last()[0]->data();
    }

    private function editCopy(array $edit): string
    {
        return (string) ($edit['caption'] ?? $edit['text'] ?? '');
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
