<?php

namespace Tests\Feature;

use App\Enums\StoreBalanceEntryKind;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Models\Customer;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\StoreBalanceTopUpEvent;
use App\Models\TelegramOutboundEffect;
use App\Services\KlikQris\KlikQrisNativeQrisOutcome;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisTopUpLifecycleResult;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\StoreBalanceTopUpOutboundOutcome;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpQuoteRegressionTest extends TelegramBotTestSupport
{
    private const AMOUNT = 5000;

    private const CREATE_URL = 'https://klikqris.com/api/sandbox/qris/create';

    private const STATUS_URL = 'https://klikqris.com/api/sandbox/qris/status/*';

    private const QRIS_URL = 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png';

    private const SIGNATURE = 'top-up-signature';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-07-28 10:10:00 UTC');
        KlikQrisSandboxScenarios::configureReadySandbox();
        Http::preventStrayRequests();
    }

    public function test_fee_less_provider_quote_becomes_payable_and_hands_off_the_qris(): void
    {
        $this->fakeProvider();
        $topUp = $this->makePendingTopUp();

        $result = $this->lifecycle($topUp);
        $fresh = $topUp->fresh();

        $this->assertTrue($result->isPayable());
        $this->assertSame(KlikQrisNativeQrisOutcome::Payable, $result->outcome());
        $this->assertSame(StoreBalanceTopUpStatus::Payable, $fresh->status);
        $this->assertSame('created', $fresh->provider_create_state);
        $this->assertSame(16, $fresh->provider_fee);
        $this->assertSame(self::AMOUNT + 16, $fresh->provider_total_payment);
        $this->assertSame(self::QRIS_URL, $fresh->provider_qris_url);
        $this->assertSame('qris', $fresh->provider_payment_method);
        $this->assertNotNull($fresh->provider_created_at);
        $this->assertSame(1, StoreBalanceTopUpEvent::query()->where('event_type', 'provider_create')->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());

        $handoff = app(StoreBalanceTopUpOutbound::class)->handoffInstructions($fresh);

        $this->assertSame(StoreBalanceTopUpOutboundOutcome::Queued, $handoff->outcome);
        $effect = TelegramOutboundEffect::query()->sole();
        $this->assertSame(TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP, $effect->effect_type);
        $this->assertSame('pending', $effect->state->value);
    }

    public function test_derived_fee_round_trips_through_the_persisted_quote_without_a_second_provider_call(): void
    {
        $this->fakeProvider();
        $topUp = $this->makePendingTopUp();

        $this->lifecycle($topUp);
        $fresh = $topUp->fresh();

        $reloaded = app(KlikQrisNativeQrisService::class)->lifecycleForTopUp($fresh);

        $this->assertTrue($reloaded->isPayable());
        $this->assertSame(16, $reloaded->payable()?->providerFee());
        $this->assertSame(self::AMOUNT, $reloaded->payable()?->creditAmount());
        $this->assertSame(self::AMOUNT + 16, $reloaded->payable()?->totalAmount());
        $this->assertSame(16, $fresh->provider_fee);
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('providerQuoteCases')]
    public function test_provider_quote_variants_never_persist_an_unusable_payable(
        array $overrides,
        int $httpStatus,
        bool $payable,
        ?int $fee,
        KlikQrisNativeQrisOutcome $outcome,
    ): void {
        $this->fakeProvider($overrides, [], $httpStatus);
        $topUp = $this->makePendingTopUp();

        $result = $this->lifecycle($topUp);
        $fresh = $topUp->fresh();

        $this->assertSame($payable, $result->isPayable());
        $this->assertSame($outcome, $result->outcome());
        $this->assertSame($fee, $fresh->provider_fee);
        $this->assertSame($payable, $fresh->status === StoreBalanceTopUpStatus::Payable);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->assertSame(0, TelegramOutboundEffect::query()->count());

        if (! $payable) {
            $this->assertNull($result->payable());
            $this->assertNotSame('created', (string) $fresh->provider_create_state);
        }
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: int, 2: bool, 3: ?int, 4: KlikQrisNativeQrisOutcome}>
     */
    public static function providerQuoteCases(): array
    {
        return [
            // The production outage: KlikQRIS returned a final total without a
            // fee field, so the top-up could never become payable.
            'fee omitted with total above credit' => [[], 200, true, 16, KlikQrisNativeQrisOutcome::Payable],
            'fee omitted with total equal to credit' => [['total_amount' => self::AMOUNT], 200, true, 0, KlikQrisNativeQrisOutcome::Payable],
            'explicit fee above zero' => [['fee' => 16], 200, true, 16, KlikQrisNativeQrisOutcome::Payable],
            'explicit zero fee' => [['fee' => 0, 'total_amount' => self::AMOUNT], 200, true, 0, KlikQrisNativeQrisOutcome::Payable],
            'explicit null fee behaves as omitted' => [['fee' => null], 200, true, 16, KlikQrisNativeQrisOutcome::Payable],
            'provider_fee alias' => [['provider_fee' => 16], 200, true, 16, KlikQrisNativeQrisOutcome::Payable],
            'fee_amount alias' => [['fee_amount' => 16], 200, true, 16, KlikQrisNativeQrisOutcome::Payable],
            'total below credit stays unpayable' => [['total_amount' => 4000], 200, false, null, KlikQrisNativeQrisOutcome::ProviderTotalPending],
            'fee aliases disagree' => [['fee' => 16, 'provider_fee' => 20], 200, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
            'fractional fee' => [['fee' => '16.5'], 200, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
            'negative fee' => [['fee' => '-1'], 200, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
            'missing total' => [['total_amount' => null], 200, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
            'fractional total' => [['total_amount' => '5016.5'], 200, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
            'pending without qris media' => [['qris_url' => null], 200, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
            'base64 only qris media stays unpayable' => [['qris_url' => null, 'qris_image' => KlikQrisSandboxScenarios::pngDataUri()], 200, false, 16, KlikQrisNativeQrisOutcome::InvalidProviderResult],
            'provider expired quote' => [['status' => 'EXPIRED'], 200, false, 16, KlikQrisNativeQrisOutcome::Expired],
            'provider reports success on create' => [['status' => 'SUCCESS', 'paid_at' => '2026-07-28T10:05:00Z'], 200, false, 16, KlikQrisNativeQrisOutcome::InvalidProviderResult],
            'provider http failure' => [[], 500, false, null, KlikQrisNativeQrisOutcome::RecoveryPending],
        ];
    }

    public function test_status_recovery_heals_a_fee_less_top_up_created_before_the_fix(): void
    {
        $topUp = $this->makePendingTopUp([
            'provider_create_state' => 'provider_fee_missing',
            'provider_create_last_error' => 'provider_fee_missing',
            'provider_total_payment' => self::AMOUNT + 16,
            'provider_payment_method' => 'qris',
            'provider_created_at' => now()->subMinutes(2),
            'provider_expires_at' => now()->addMinutes(13),
            'provider_qris_url' => self::QRIS_URL,
            'provider_signature' => self::SIGNATURE,
        ]);
        $this->fakeProvider([], [
            'status' => 'PENDING',
            'signature' => self::SIGNATURE,
            'expired_at' => now()->addMinutes(13)->toISOString(),
        ]);

        $result = app(StoreBalanceTopUpService::class)->reconcile($topUp);
        $fresh = $topUp->fresh();

        $this->assertTrue($result->isPayable());
        $this->assertSame(StoreBalanceTopUpStatus::Payable, $fresh->status);
        $this->assertSame('created', $fresh->provider_create_state);
        $this->assertSame(16, $fresh->provider_fee);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/qris/create'));
    }

    public function test_fee_less_quote_delivers_the_qris_and_credits_the_balance_exactly_once(): void
    {
        $this->fakeProvider([], [
            'status' => 'SUCCESS',
            'paid_at' => now()->subMinute()->toISOString(),
            'signature' => self::SIGNATURE,
        ]);
        $topUp = $this->makePendingTopUp();

        $this->assertTrue($this->lifecycle($topUp)->isPayable());

        $handoff = app(StoreBalanceTopUpOutbound::class)->handoffInstructions($topUp->fresh());
        $this->assertSame(StoreBalanceTopUpOutboundOutcome::Queued, $handoff->outcome);

        $callback = [
            'order_id' => $topUp->merchant_ref,
            'amount' => self::AMOUNT,
            'total_amount' => self::AMOUNT + 16,
            'status' => 'SUCCESS',
            'signature' => self::SIGNATURE,
        ];

        $this->postJson('/api/webhook/klikqris/sandbox', $callback)->assertOk();

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(StoreBalanceEntryKind::TopUpCredit, StoreBalanceEntry::query()->sole()->kind);
        $this->assertSame(self::AMOUNT, StoreBalanceEntry::query()->sole()->amount);
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());

        $this->postJson('/api/webhook/klikqris/sandbox', $callback)->assertOk();

        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT)
            ->count());
    }

    public function test_confirmed_payable_surface_does_not_repeat_the_pre_confirmation_copy(): void
    {
        $this->fakeProvider();
        $topUp = $this->makePendingTopUp();
        $result = $this->lifecycle($topUp);

        $surface = app(TelegramMenuIntents::class)->fundingTopUpSurface(
            $topUp->customer,
            $topUp->fresh(),
            $result,
            instructionsSent: true,
            confirmationReceived: true,
        );

        $this->assertStringContainsString('Confirmation received.', $surface->caption);
        $this->assertStringContainsString('Store Balance credit: Rp 5.000', $surface->caption);
        $this->assertStringContainsString('QRIS was sent in a separate message.', $surface->caption);
        $this->assertStringContainsString('Pay before:', $surface->caption);
        $this->assertStringNotContainsString('No payment invoice exists yet.', $surface->caption);
    }

    public function test_confirmed_failure_surface_does_not_contradict_itself(): void
    {
        $topUp = $this->makePendingTopUp();

        $surface = app(TelegramMenuIntents::class)->fundingTopUpSurface(
            $topUp->customer,
            $topUp,
            KlikQrisTopUpLifecycleResult::state(KlikQrisNativeQrisOutcome::ProviderTotalPending),
            confirmationReceived: true,
        );

        $this->assertStringContainsString('Confirmation received.', $surface->caption);
        $this->assertStringContainsString('Store Balance credit: Rp 5.000', $surface->caption);
        $this->assertStringContainsString('Payment status could not be confirmed yet.', $surface->caption);
        $this->assertStringContainsString('Need help? Open Support', $surface->caption);
        $this->assertStringNotContainsString('No payment invoice exists yet.', $surface->caption);
    }

    public function test_pre_confirmation_surface_still_states_that_no_invoice_exists(): void
    {
        $topUp = $this->makePendingTopUp();
        $intent = StoreBalanceFundingIntent::query()->whereKey($topUp->store_balance_funding_intent_id)->sole();

        $surface = app(TelegramMenuIntents::class)->fundingConfirmationSurface($topUp->customer, $intent);

        $this->assertStringContainsString('No payment invoice exists yet.', $surface->caption);
        $this->assertStringContainsString('Store Balance credit: Rp 5.000', $surface->caption);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makePendingTopUp(array $overrides = []): StoreBalanceTopUp
    {
        $customer = Customer::factory()->create(['locale' => 'en']);
        $chatId = (string) $customer->telegram_id;
        $merchantRef = 'BALANCE-'.Str::upper(Str::random(12));
        $expiresAt = now()->addMinutes(10)->startOfSecond();
        $intent = StoreBalanceFundingIntent::create([
            'customer_id' => $customer->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => $chatId,
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => self::AMOUNT,
            'nonce' => bin2hex(random_bytes(16)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $expiresAt,
            'claimed_at' => now(),
        ]);

        return StoreBalanceTopUp::create(array_replace([
            'customer_id' => $customer->id,
            'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => null,
            'chat_id' => $chatId,
            'canonical_message_id' => 1,
            'locale' => 'en',
            'provider' => 'klikqris',
            'merchant_ref' => $merchantRef,
            'idempotency_key' => 'top-up-'.$merchantRef,
            'currency' => 'IDR',
            'amount' => self::AMOUNT,
            'status' => StoreBalanceTopUpStatus::Pending,
            'provider_create_state' => null,
            'provider_payload' => null,
            'provider_fee' => null,
            'provider_total_payment' => null,
            'expires_at' => $expiresAt,
        ], $overrides));
    }

    private function lifecycle(StoreBalanceTopUp $topUp): KlikQrisTopUpLifecycleResult
    {
        return app(KlikQrisNativeQrisService::class)->lifecycleForTopUp($topUp);
    }

    /**
     * @param  array<string, mixed>  $createOverrides
     * @param  array<string, mixed>  $statusOverrides
     */
    private function fakeProvider(array $createOverrides = [], array $statusOverrides = [], int $createHttpStatus = 200): void
    {
        Http::fake([
            self::CREATE_URL => function (Request $request) use ($createOverrides, $createHttpStatus) {
                if ($createHttpStatus !== 200) {
                    return Http::response(['message' => 'provider failure'], $createHttpStatus);
                }

                return Http::response($this->providerPayload(array_replace(
                    ['order_id' => $request['order_id']],
                    $createOverrides,
                )));
            },
            self::STATUS_URL => fn (Request $request) => Http::response($this->providerPayload(array_replace(
                ['order_id' => basename($request->url())],
                $statusOverrides,
            ))),
        ]);
    }

    /**
     * The fee-less shape KlikQRIS actually returns: no fee field is present.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function providerPayload(array $overrides = []): array
    {
        return ['data' => array_replace([
            'amount' => self::AMOUNT,
            'total_amount' => self::AMOUNT + 16,
            'status' => 'PENDING',
            'qris_url' => self::QRIS_URL,
            'expired_at' => now()->addMinutes(15)->toISOString(),
            'signature' => self::SIGNATURE,
        ], $overrides)];
    }
}
