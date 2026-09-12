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
use App\Services\Sakurupiah\SakurupiahCreateInvoice;
use App\Services\Sakurupiah\SakurupiahInvoice;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramUpdateProcessor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpSakurupiahRailTest extends TelegramBotTestSupport
{
    private const BUYER_CHAT_ID = 46701;

    private const QR_PAYLOAD = '00020101021226610014ID.CO.QRIS.WWW0118936000000000000000000000000003033ID10214';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-28 10:10:00 UTC');
        $this->fakeTelegramHttp();
        config()->set('services.sakurupiah.sandbox_api_id', 'test-api-id');
        config()->set('services.sakurupiah.sandbox_api_key', 'test-api-key');
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'sakurupiah',
            'is_active' => true,
            'whatsapp_support_number' => '081234567890',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_confirmation_creates_a_sakurupiah_qris_top_up_and_delivers_a_rendered_qr(): void
    {
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')
            ->once()
            ->andReturnUsing(fn (SakurupiahCreateInvoice $request): SakurupiahInvoice => SakurupiahInvoice::fromPayload([
                'trx_id' => 'TRX-TOPUP-1',
                'merchant_ref' => $request->merchantRef,
                'payment_kode' => 'QRISC',
                'status' => 'pending',
                'total' => 5000,
                'amount' => 5000,
                'fee' => 0,
                'date' => '2026-07-28',
                'time' => '10:10:00',
                'qr' => self::QR_PAYLOAD,
                'expired' => 24,
            ], $request));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);

        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46704, 46705);

        $this->assertSame(StoreBalanceTopUpStatus::Payable, $topUp->status);
        $this->assertSame('sakurupiah', $topUp->provider);
        $this->assertSame('TRX-TOPUP-1', $topUp->provider_reference);
        $this->assertSame(self::QR_PAYLOAD, (string) $topUp->provider_qris_payload);
        $this->assertStringNotContainsString(self::QR_PAYLOAD, json_encode($topUp->provider_payload, JSON_THROW_ON_ERROR));

        $effect = TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->sole();
        $this->assertSame('balance-top-up:'.$topUp->getKey().':sakurupiah-instructions:v1', $effect->effect_key);
        $this->assertSame('balance-top-up-sakurupiah-v1', $effect->template_version);
        $this->assertSame('sent', $effect->state->value);

        $photo = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->last()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertStringContainsString('Balance credit after payment confirmation: Rp5.000', $caption);
        $this->assertNotEmpty($this->telegramRequestField($photo, 'photo'));
    }

    public function test_authoritative_status_credits_the_requested_amount_exactly_once(): void
    {
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('createInvoice')->once()->andReturnUsing(
            fn (SakurupiahCreateInvoice $request): SakurupiahInvoice => SakurupiahInvoice::fromPayload([
                'trx_id' => 'TRX-TOPUP-2',
                'merchant_ref' => $request->merchantRef,
                'payment_kode' => 'QRISC',
                'status' => 'pending',
                'total' => 5000,
                'amount' => 5000,
                'fee' => 0,
                'date' => '2026-07-28',
                'time' => '10:10:00',
                'qr' => self::QR_PAYLOAD,
                'expired' => 24,
            ], $request),
        );
        $adapter->shouldReceive('transactionStatus')
            ->andReturnUsing(fn (string $trxId): SakurupiahStatus => SakurupiahStatus::fromPayload([
                'trx_id' => $trxId,
                'merchant_ref' => StoreBalanceTopUp::query()->sole()->merchant_ref,
                'payment_kode' => 'QRISC',
                'status' => 'berhasil',
                'total' => 5000,
                'amount' => 5000,
                'fee' => 0,
                'tanggal' => '2026-07-28',
                'waktu' => '10:12:00',
                'expired' => 24,
            ], $trxId));
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);

        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46714, 46715);

        $this->artisan('sakurupiah:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->expectsOutputToContain('paid=1')
            ->assertSuccessful();

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(5000, (int) StoreBalanceEntry::query()->sole()->amount);

        $this->artisan('sakurupiah:reconcile-balance-top-ups --chunk=10 --max-top-ups=10')
            ->assertSuccessful();

        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(
            1,
            TelegramOutboundEffect::query()
                ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
                ->count(),
        );
    }

    public function test_unready_provider_fails_closed_without_creating_an_invoice(): void
    {
        config()->set('services.sakurupiah.sandbox_api_key', null);
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldNotReceive('createInvoice');
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);

        Customer::factory()->create(['telegram_id' => self::BUYER_CHAT_ID, 'locale' => 'en']);
        $root = $this->openFundingChooser();
        $topUp = $this->confirmTopUp($root, 46724, 46725);

        $this->assertSame(StoreBalanceTopUpStatus::Failed, $topUp->status);
        $this->assertSame('provider_unavailable', $topUp->provider_create_state);
        $this->assertSame(0, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->count());
    }

    private function openFundingChooser(): TelegramNavigationRoot
    {
        $this->postTelegramUpdate($this->messageUpdate(46701, '/start', [], self::BUYER_CHAT_ID));
        $root = TelegramNavigationRoot::query()->sole();
        $this->postTelegramUpdate($this->callbackUpdate(
            46702,
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
            'top-up-confirm-sakurupiah',
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
