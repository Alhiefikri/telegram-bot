<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpOutcome;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Events\StoreBalanceTopUpSettled;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahCreatePreRequestException;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpLifecycleResolver;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\QrisTestPayload;
use Tests\TestCase;

final class SakurupiahTopUpChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-27 10:00:00 UTC');
        Event::fake([StoreBalanceTopUpSettled::class]);
        Http::preventStrayRequests();
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'api-test',
            'sandbox_api_key' => 'secret-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => [
                'currency' => 'IDR',
                'currency_confirmed' => true,
                'fee_payer' => 'merchant',
                'merchant_fee' => 1,
                'fee_confirmed' => true,
                'expiry_hours' => 24,
                'expiry_confirmed' => true,
            ],
        ]));
        ManualPaymentSetting::create([
            'payment_mode' => 'sakurupiah',
            'payment_provider_version' => 7,
            'sakurupiah_channel' => 'QRIS',
            'is_active' => true,
        ]);
    }

    #[DataProvider('channels')]
    public function test_selected_channel_survives_setting_switch_through_create_and_settlement(string $channel, int $hours): void
    {
        $setting = ManualPaymentSetting::current();
        $setting->update(['sakurupiah_channel' => $channel]);
        $intent = $this->intent();
        $topUp = app(StoreBalanceTopUpService::class)->createOrReuseConfirmedIntent($intent);
        $this->assertSame($channel, $topUp->sakurupiah_channel);
        $this->assertSame('QRIS', $topUp->provider_payment_method);
        $this->assertSame($setting->id, $topUp->payment_setting_id);
        $this->assertSame(7, $topUp->payment_provider_version);

        $setting->update(['sakurupiah_channel' => $channel === 'QRIS' ? 'QRISC' : 'QRIS', 'payment_provider_version' => 8]);
        $reused = app(StoreBalanceTopUpService::class)->createOrReuseConfirmedIntent($intent);
        $this->assertSame($topUp->id, $reused->id);
        $this->assertSame($channel, $reused->sakurupiah_channel);
        $this->assertSame(7, $reused->payment_provider_version);

        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturnUsing(function (SakurupiahCreateInvoice $request) use ($topUp, $channel, $hours): SakurupiahInvoice {
            $this->assertSame($channel, $request->method);
            $this->assertSame($hours, $request->expired);

            return $this->invoice($topUp, $channel);
        });
        $adapter->shouldReceive('transactionStatus')->once()->andReturn($this->providerStatus($topUp, $channel));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $service = app(StoreBalanceTopUpService::class);
        $this->assertTrue($service->lifecycle($topUp)->isPayable());
        $service->reconcile($topUp);

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame($channel, $topUp->fresh()->sakurupiah_channel);
        $this->assertSame(5000, StoreBalanceEntry::query()->sole()->amount);
    }

    public static function channels(): array
    {
        return ['direct QRIS' => ['QRIS', 24], 'legacy QRISC' => ['QRISC', 24], 'QRISMU' => ['QRISMU', 12], 'QRIS2' => ['QRIS2', 24]];
    }

    public function test_legacy_null_snapshot_uses_qrisc_not_the_generic_qris_method(): void
    {
        $topUp = $this->topUp();
        $topUp->update(['sakurupiah_channel' => null]);
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturnUsing(function (SakurupiahCreateInvoice $request) use ($topUp): SakurupiahInvoice {
            $this->assertSame('QRISC', $request->method);

            return $this->invoice($topUp, 'QRISC');
        });
        $adapter->shouldReceive('transactionStatus')->once()->andReturn($this->providerStatus($topUp, 'QRISC'));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $service = app(StoreBalanceTopUpService::class);
        $this->assertTrue($service->lifecycle($topUp)->isPayable());
        $service->reconcile($topUp);

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertNull($topUp->fresh()->sakurupiah_channel);
        $this->assertSame('QRIS', $topUp->fresh()->provider_payment_method);
    }

    public function test_wrong_create_channel_is_rejected_without_fallback(): void
    {
        $topUp = $this->topUp();
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturn($this->invoice($topUp, 'QRISC'));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $service = app(StoreBalanceTopUpService::class);
        $this->assertSame(StoreBalanceTopUpOutcome::InvalidProviderResult, $service->lifecycle($topUp)->outcome());
        $this->assertSame(StoreBalanceTopUpOutcome::InvalidProviderResult, $service->lifecycle($topUp)->outcome());
        $this->assertNull($topUp->fresh()->provider_reference);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_wrong_status_channel_never_settles_or_recovers_the_quote(): void
    {
        $topUp = $this->topUp();
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturn($this->invoice($topUp, 'QRIS'));
        $adapter->shouldReceive('transactionStatus')->once()->andReturn($this->providerStatus($topUp, 'QRISC'));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $service = app(StoreBalanceTopUpService::class);
        $this->assertTrue($service->lifecycle($topUp)->isPayable());
        $this->assertSame(StoreBalanceTopUpOutcome::InvalidProviderResult, $service->reconcile($topUp)->outcome());
        $this->assertSame(StoreBalanceTopUpStatus::InvalidProviderResult, $topUp->fresh()->status);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_qris_provider_image_is_downloaded_as_an_image_instead_of_encoded_as_url_text(): void
    {
        $topUp = $this->topUp();
        $url = 'https://sakurupiah.id/qris/topup.png?token=private';
        $png = app(TelegramQrCodeRenderer::class)->png(QrisTestPayload::forAmount(5000));
        Http::fake([$url => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturn($this->invoice($topUp, 'QRIS', $url));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $service = app(StoreBalanceTopUpService::class);
        $result = $service->lifecycle($topUp);
        $this->assertTrue($result->isPayable());
        $this->assertNull($topUp->fresh()->provider_qris_payload);
        $this->assertSame($url, $topUp->fresh()->provider_qris_url);
        $this->assertStringNotContainsString($url, $topUp->fresh()->getRawOriginal('provider_qris_url'));
        $this->assertSame($png, $service->instructionAttachment($topUp->fresh(), $result->payable())->photoContents());
        Http::assertSentCount(1);
    }

    public function test_arbitrary_qr_image_url_is_not_made_payable_or_fetched(): void
    {
        $topUp = $this->topUp();
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturn($this->invoice($topUp, 'QRIS', 'https://evil.example/qris.png'));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $result = app(StoreBalanceTopUpService::class)->lifecycle($topUp);

        $this->assertFalse($result->isPayable());
        $this->assertSame(StoreBalanceTopUpOutcome::InvalidProviderResult, $result->outcome());
        $this->assertNull($topUp->fresh()->provider_qris_url);
        Http::assertNothingSent();
    }

    public function test_pending_status_recovers_image_on_the_original_channel_without_create(): void
    {
        $topUp = $this->topUp();
        $topUp->update(['provider_reference' => 'trx-'.$topUp->id, 'provider_create_state' => 'outcome_unknown']);
        ManualPaymentSetting::current()->update(['sakurupiah_channel' => 'QRISC']);
        $url = 'https://sakurupiah.id/qris/recovered.png';
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldNotReceive('createInvoice');
        $adapter->shouldReceive('transactionStatus')->once()->andReturn($this->providerStatus($topUp, 'QRIS', [
            'status' => 'pending', 'qr' => $url,
        ]));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);

        $result = app(StoreBalanceTopUpService::class)->reconcile($topUp);

        $this->assertTrue($result->isPayable());
        $this->assertSame('QRIS', $topUp->fresh()->sakurupiah_channel);
        $this->assertSame($url, $topUp->fresh()->provider_qris_url);
    }

    public function test_ambiguous_create_never_creates_again_even_after_configuration_changes(): void
    {
        $topUp = $this->topUp();
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andThrow(new RuntimeException('ambiguous provider outcome'));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
        $service = app(StoreBalanceTopUpService::class);

        $this->assertSame(StoreBalanceTopUpOutcome::RecoveryPending, $service->lifecycle($topUp)->outcome());
        config()->set('services.sakurupiah.sandbox_api_key', null);
        $this->assertSame(StoreBalanceTopUpOutcome::RecoveryPending, $service->lifecycle($topUp)->outcome());
        config()->set('services.sakurupiah.sandbox_api_key', 'secret-key');
        ManualPaymentSetting::current()->update(['sakurupiah_channel' => 'QRISC']);
        $this->assertSame(StoreBalanceTopUpOutcome::RecoveryPending, $service->lifecycle($topUp)->outcome());
        $this->assertSame('outcome_unknown', $topUp->fresh()->provider_create_state);
    }

    public function test_pre_request_retry_keeps_the_original_channel(): void
    {
        $topUp = $this->topUp();
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->ordered()->andThrow(new SakurupiahCreatePreRequestException('phone missing'));
        $adapter->shouldReceive('createInvoice')->once()->ordered()->andReturnUsing(function (SakurupiahCreateInvoice $request) use ($topUp): SakurupiahInvoice {
            $this->assertSame('QRIS', $request->method);

            return $this->invoice($topUp, 'QRIS');
        });
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
        $service = app(StoreBalanceTopUpService::class);
        $this->assertSame(StoreBalanceTopUpOutcome::ProviderUnavailable, $service->lifecycle($topUp)->outcome());
        ManualPaymentSetting::current()->update(['sakurupiah_channel' => 'QRISMU']);

        $this->assertTrue($service->lifecycle($topUp)->isPayable());
        $this->assertSame('QRIS', $topUp->fresh()->sakurupiah_channel);
    }

    private function topUp(): StoreBalanceTopUp
    {
        return app(StoreBalanceTopUpService::class)->createOrReuseConfirmedIntent($this->intent());
    }

    private function intent(): StoreBalanceFundingIntent
    {
        $customer = Customer::factory()->create(['phone_number' => '08123456789', 'locale' => 'en']);
        $root = TelegramNavigationRoot::create([
            'customer_id' => $customer->id,
            'chat_id' => (string) $customer->telegram_id,
            'message_id' => 123,
            'media_kind' => TelegramNavigationMediaKind::Photo,
            'screen' => TelegramNavigationScreen::AddBalance,
            'locale' => 'en',
            'generation' => 1,
        ]);

        return StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => $root->id,
            'chat_id' => $root->chat_id,
            'canonical_message_id' => $root->message_id,
            'policy_version' => 1,
            'amount' => 5000,
            'nonce' => bin2hex(random_bytes(16)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => now()->addMinutes(10),
            'claimed_at' => now(),
        ]);
    }

    private function invoice(StoreBalanceTopUp $topUp, string $channel, ?string $qr = null): SakurupiahInvoice
    {
        return SakurupiahInvoice::fromPayload([
            'trx_id' => 'trx-'.$topUp->id,
            'merchant_ref' => $topUp->merchant_ref,
            'payment_kode' => $channel,
            'status' => 'pending',
            'total' => 5000,
            'amount' => 5000,
            'fee' => 0,
            'date' => '2026-10-27',
            'time' => '10:00:00',
            'expired' => 24,
            'qr' => $qr ?? QrisTestPayload::forAmount(5000),
        ]);
    }

    private function providerStatus(StoreBalanceTopUp $topUp, string $channel, array $overrides = []): SakurupiahStatus
    {
        return SakurupiahStatus::fromPayload(array_replace([
            'trx_id' => 'trx-'.$topUp->id,
            'merchant_ref' => $topUp->merchant_ref,
            'payment_kode' => $channel,
            'status' => 'berhasil',
            'total' => 5000,
            'amount' => 5000,
            'fee' => 0,
            'date' => '2026-10-27',
            'time' => '10:01:00',
            'expired' => 24,
        ], $overrides));
    }
}
