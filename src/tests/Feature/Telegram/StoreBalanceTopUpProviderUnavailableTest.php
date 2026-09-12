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
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpProviderUnavailableTest extends TelegramBotTestSupport
{
    private const BUYER_CHAT_ID = 46801;

    public function test_selected_klikqris_without_credentials_reports_an_unavailable_provider(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $this->fakeTelegramHttp();
        // The store selects KlikQRIS while its sandbox credentials are absent:
        // exactly the dogfood configuration that produced no QR silently.
        config()->set('services.klikqris', [
            ...KlikQrisSandboxScenarios::readyConfig(),
            'sandbox_api_key' => null,
            'sandbox_merchant_id' => null,
        ]);
        config()->set('queue.default', 'database');
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'klikqris',
            'is_active' => true,
        ]);

        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46804, 46805);

        $this->assertSame(StoreBalanceTopUpStatus::Failed, $topUp->status);
        $this->assertSame('provider_unavailable', $topUp->provider_create_state);
        $this->assertSame('klikqris', $topUp->provider);
        $this->assertNull($topUp->provider_qris_url);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->count());

        $presentation = app(StoreBalanceTopUpService::class)->presentation($topUp);
        $this->assertSame('provider_unavailable', $presentation->outcome()->value);
        $this->assertTrue($presentation->canRetry());

        $edit = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/editMessageText')
            || str_ends_with($request->url(), '/editMessageCaption'))->last()[0];
        $caption = (string) ($edit['caption'] ?? $edit['text'] ?? '');
        $this->assertStringContainsString('The active payment method is not ready yet.', $caption);
    }

    public function test_provider_retry_recovers_after_the_configuration_is_ready(): void
    {
        $this->travelTo('2026-07-28 10:10:00 UTC');
        $this->fakeTelegramHttp([
            'https://klikqris.com/api/sandbox/qris/create' => fn (Request $request) => Http::response(['data' => [
                'order_id' => $request['order_id'],
                'amount' => 5000,
                'total_amount' => 5016,
                'fee' => 16,
                'status' => 'PENDING',
                'qris_url' => 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png',
                'signature' => 'top-up-signature',
                'expired_at' => now()->addMinutes(15)->toISOString(),
            ]]),
            'https://klikqris.com/storage/sandbox/qris_balance_top_up.png' => Http::response("\x89PNG\r\n\x1A\nx", 200, ['Content-Type' => 'image/png']),
        ]);
        KlikQrisSandboxScenarios::configureReadySandbox();
        KlikQrisSandboxScenarios::selectKlikQrisProvider();

        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46814, 46815);

        $this->assertSame('created', $topUp->provider_create_state);
        $this->assertTrue(app(StoreBalanceTopUpService::class)->presentation($topUp)->isPayable());
    }

    private function openFundingChooser(): TelegramNavigationRoot
    {
        $this->postTelegramUpdate($this->messageUpdate(46801, '/start', [], self::BUYER_CHAT_ID));
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46802,
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
            'top-up-confirm-unavailable',
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
