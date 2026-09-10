<?php

namespace Tests\Feature\Telegram;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Jobs\ProcessTelegramUpdate;
use App\Jobs\SendStoreBalanceTopUpInstructions;
use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Models\TelegramUpdate;
use App\Services\StoreBalance\StoreBalanceTopUpService;
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
        $this->postTelegramUpdate($this->callbackUpdate(
            46205,
            'top-up-check',
            StoreBalanceTopUpService::actionCallback('check', $topUp->getKey()),
            [],
            46201,
            'private',
            $effect->telegram_message_id,
        ));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
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
