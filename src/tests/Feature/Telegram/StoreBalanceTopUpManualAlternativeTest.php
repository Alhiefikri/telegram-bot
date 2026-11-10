<?php

namespace Tests\Feature\Telegram;

use App\Enums\ManualStoreBalanceTopUpDecision;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Enums\StoreBalanceTopUpStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceTopUp;
use App\Models\TelegramNavigationRoot;
use App\Models\TelegramOutboundEffect;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTopUpProviderAdapter;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Services\Sakurupiah\SakurupiahAmount;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahStatus;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\StoreBalance\StoreBalanceTopUpService;
use App\Services\StoreBalance\TopUp\ManualStoreBalanceTopUpConfirmationService;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpLifecycleResolver;
use App\Services\Telegram\BalanceTopUpInstructionDeliveryPipeline;
use App\Services\Telegram\Callbacks\TelegramAdminPaymentCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBalanceFundingCallbackHandler;
use App\Services\Telegram\StoreBalanceTopUpOutbound;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramManualTopUpIntents;
use App\Services\Telegram\TelegramMenuIntents;
use App\Services\Telegram\TelegramNavigationRootService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\WijayaPay\WijayaPayCallback;
use App\Services\WijayaPay\WijayaPayProviderAdapter;
use App\Services\WijayaPay\WijayaPayProviderException;
use App\Services\WijayaPay\WijayaPayStatus;
use App\Services\WijayaPay\WijayaPayStatusResponse;
use App\Services\WijayaPay\WijayaPayStoreBalanceTopUpCallbackProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\KlikQrisSandboxScenarios;
use Tests\Support\TelegramBotTestSupport;

final class StoreBalanceTopUpManualAlternativeTest extends TelegramBotTestSupport
{
    private const BUYER = '47901';

    private const ADMIN = '990001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 10:00:00 UTC');
        $this->fakeTelegramHttp();
        ManualPaymentSetting::query()->create([
            'payment_mode' => 'wijayapay',
            'is_active' => true,
            'admin_telegram_chat_id' => self::ADMIN,
        ]);
        config()->set('services.wijayapay', array_replace(config('services.wijayapay', []), [
            'base_url' => 'https://gateway.wijayapay.com',
            'code_merchant' => 'WP-test-merchant',
            'api_key' => 'test-api-key',
            'callback_url' => 'https://store.example.test/api/webhook/wijayapay',
            'return_url' => 'https://store.example.test/shop/orders',
            'allowed_hosts' => ['gateway.wijayapay.com', 'wijayapay.com'],
            'callback_allowed_hosts' => ['store.example.test'],
            'return_allowed_hosts' => ['store.example.test'],
            'qris_contract' => ['currency' => 'IDR', 'currency_confirmed' => true, 'max_fee_bps' => 500, 'expiry_confirmed' => true],
        ]));
    }

    public function test_preview_does_not_switch_or_move_money_and_confirmation_replaces_the_original_qr(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $original = $this->sentInstruction($topUp);
        $providerSnapshot = $this->providerSnapshot($topUp);
        $this->statusProvider($topUp, WijayaPayStatus::Pending);

        $this->customerAction($customer, $root, $topUp, 'manual');
        $this->assertNull($topUp->fresh()->manual_alternative_at);
        $this->assertNotNull($topUp->fresh()->manual_alternative_requested_at);
        $this->assertNull($original->fresh()->telegram_message_deleted_at);
        $this->assertSame(0, StoreBalanceEntry::query()->count());

        $this->customerAction($customer, $root->fresh(), $topUp, 'manual_confirm');
        $fresh = $topUp->fresh();
        $this->assertSame(StoreBalanceTopUpStatus::AwaitingConfirmation, $fresh->status);
        $this->assertNotNull($fresh->manual_alternative_at);
        $this->assertSame($providerSnapshot, $this->providerSnapshot($fresh));
        $this->assertNotNull($original->fresh()->telegram_message_deleted_at);
        $this->assertSame('wijayapay', ManualPaymentSetting::query()->sole()->payment_mode);
        $this->assertSame(1, StoreBalanceTopUp::query()->count());
        $this->assertSame(1, StoreBalanceFundingIntent::query()->count());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $manual = TelegramOutboundEffect::query()->where('effect_key', 'balance-top-up:'.$topUp->id.':manual-instructions:v1')->sole();
        $this->assertSame(TelegramOutboundEffectState::Sent, $manual->state);
        $presentation = app(StoreBalanceTopUpService::class)->presentation($fresh);
        $this->assertSame(5000, $presentation->payable()->totalAmount());
        $this->assertSame(0, $presentation->payable()->providerFee());
        $this->assertFalse(app(StoreBalanceTopUpOutbound::class)->hasSentInstructionFor($fresh, self::BUYER, 123450));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/deleteMessage') && (int) $request['message_id'] === 123450);
    }

    public function test_paid_authoritative_recheck_settles_automatic_instead_of_exposing_manual_qr(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $this->statusProvider($topUp, WijayaPayStatus::Paid);
        $service = app(StoreBalanceTopUpService::class);
        $this->assertTrue($service->requestManualAlternative($customer, self::BUYER, $topUp));
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->assertSame(StoreBalanceTopUpStatus::Paid, $topUp->fresh()->status);
        $this->assertSame('wijayapay', $topUp->fresh()->settled_provider);
        $this->assertNull($topUp->fresh()->manual_alternative_at);
        $this->assertSame(5000, (int) StoreBalanceEntry::query()->sole()->amount);
    }

    public function test_unknown_status_blocks_switch_without_deleting_the_original_qr(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $original = $this->sentInstruction($topUp);
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->once()->andThrow(new WijayaPayProviderException('Unavailable', operation: 'transactionStatus', reason: 'transport'));
        $provider->shouldNotReceive('createInvoice');
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->assertNull($topUp->fresh()->manual_alternative_at);
        $this->assertNull($original->fresh()->telegram_message_deleted_at);
        $this->assertNull($topUp->fresh()->manual_alternative_switch_token);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    #[TestWith(['unknown'])]
    #[TestWith(['sending'])]
    #[TestWith(['manual_review'])]
    public function test_uncertain_original_instruction_delivery_blocks_a_second_visible_qr(string $state): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $effect = $this->sentInstruction($topUp);
        $effect->forceFill(['state' => $state])->save();
        $this->statusProvider($topUp, WijayaPayStatus::Pending);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->assertNull($topUp->fresh()->manual_alternative_at);
        $this->assertSame(1, TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)->count());
    }

    public function test_delete_failure_keeps_manual_hidden_and_provider_provenance_unchanged(): void
    {
        Http::swap(new Factory);
        $this->fakeTelegramHttp(['https://api.telegram.org/*/deleteMessage' => Http::response(['ok' => false, 'description' => "Bad Request: message can't be deleted"], 400)]);
        [$customer, $root, $topUp] = $this->topUp();
        $original = $this->sentInstruction($topUp);
        $snapshot = $this->providerSnapshot($topUp);
        $this->statusProvider($topUp, WijayaPayStatus::Pending);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->assertNull($topUp->fresh()->manual_alternative_at);
        $this->assertNull($original->fresh()->telegram_message_deleted_at);
        $this->assertSame($snapshot, $this->providerSnapshot($topUp->fresh()));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    #[TestWith(['wijayapay'])]
    #[TestWith(['sakurupiah'])]
    #[TestWith(['klikqris'])]
    public function test_definite_create_failure_can_switch_without_creating_a_remote_invoice(string $provider): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $topUp->forceFill([
            'provider' => $provider, 'status' => StoreBalanceTopUpStatus::Failed,
            'provider_reference' => null, 'provider_payload' => null, 'provider_qris_url' => null,
            'provider_created_at' => null, 'provider_expires_at' => null,
            'provider_fee' => null, 'provider_total_payment' => null,
            'provider_create_state' => 'create_failed',
        ])->save();
        $mock = Mockery::mock(WijayaPayProviderAdapter::class);
        $mock->shouldNotReceive('transactionStatus');
        $mock->shouldNotReceive('createInvoice');
        $this->app->instance(WijayaPayProviderAdapter::class, $mock);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
        $service = app(StoreBalanceTopUpService::class);
        $this->assertTrue($service->requestManualAlternative($customer, self::BUYER, $topUp));
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $fresh = $topUp->fresh();
        $this->assertSame($provider, $fresh->provider);
        $this->assertNull($fresh->provider_reference);
        $this->assertNull($fresh->provider_total_payment);
        $this->assertSame(5000, $service->presentation($fresh)->payable()->totalAmount());
        $this->submitProof($customer);
        $operator = Customer::factory()->create(['telegram_id' => self::ADMIN]);
        $this->assertSame(ManualStoreBalanceTopUpDecision::Confirmed, app(ManualStoreBalanceTopUpConfirmationService::class)->approve($fresh, $operator, self::ADMIN));
        $this->assertSame(5000, $fresh->fresh()->settledPaymentTotal());
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertNotNull(app(TelegramOutboundEffectService::class)->recordBalanceTopUpReceipt($fresh->fresh()));
    }

    public function test_foreign_stale_unconfirmed_cancelled_and_expired_requests_cannot_switch(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $foreign = Customer::factory()->create(['telegram_id' => '47902']);
        $service = app(StoreBalanceTopUpService::class);
        $this->assertFalse($service->requestManualAlternative($foreign, '47902', $topUp));
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->customerAction($foreign, $root, $topUp, 'manual');
        $this->assertNull($topUp->fresh()->manual_alternative_requested_at);
        $this->assertTrue($service->requestManualAlternative($customer, self::BUYER, $topUp));
        $service->cancelManualAlternative($customer, self::BUYER, $topUp);
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $root->forceFill(['message_id' => 987654])->save();
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $root->forceFill(['message_id' => $topUp->canonical_message_id])->save();
        $topUp->forceFill(['manual_alternative_requested_at' => now()->subMinutes(6)])->save();
        $this->assertFalse($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $topUp->forceFill(['expires_at' => now()])->save();
        $this->assertFalse($service->requestManualAlternative($customer, self::BUYER, $topUp));
        $this->assertNull($topUp->fresh()->manual_alternative_at);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_callback_proof_approval_replays_and_late_original_payment_credit_only_once(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $original = $this->sentInstruction($topUp);
        $this->statusProvider($topUp, WijayaPayStatus::Pending, WijayaPayStatus::Paid, WijayaPayStatus::Paid);
        $this->customerAction($customer, $root, $topUp, 'manual');
        $this->customerAction($customer, $root->fresh(), $topUp, 'manual_confirm');
        $this->customerAction($customer, $root->fresh(), $topUp, 'manual_confirm');
        $this->assertSame(2, TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)->count());
        $this->submitProof($customer);
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $proof = TelegramOutboundEffect::query()->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_PAYMENT_PROOF)->sole();
        $this->assertSame(TelegramOutboundEffectState::Sent, $proof->state);
        $operator = Customer::factory()->create(['telegram_id' => self::ADMIN]);
        $handler = app(TelegramAdminPaymentCallbackHandler::class);
        $handler->handle(new TelegramCallbackContext($operator, self::ADMIN, 'wrong-proof', 'admin:topup-approve:'.$topUp->id, messageId: 999999));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $foreign = Customer::factory()->create(['telegram_id' => '47903']);
        $handler->handle(new TelegramCallbackContext($foreign, '47903', 'foreign-admin', 'admin:topup-approve:'.$topUp->id, messageId: (int) $proof->telegram_message_id));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        foreach (['approve', 'replay'] as $callback) {
            $handler->handle(new TelegramCallbackContext($operator, self::ADMIN, $callback, 'admin:topup-approve:'.$topUp->id, messageId: (int) $proof->telegram_message_id));
        }
        $this->assertSame('manual', $topUp->fresh()->settled_provider);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame(5000, (int) StoreBalanceEntry::query()->sole()->amount);
        $this->assertSame(1, DB::table('external_money_receipts')->where('target_id', (string) $topUp->id)->count());
        $this->travel(20)->minutes();
        $callback = new WijayaPayCallback(
            refId: (string) $topUp->merchant_ref,
            trxReference: (string) $topUp->provider_reference,
            status: WijayaPayStatus::Paid,
            paymentMethod: 'QRIS',
            totalDibayar: 5035,
            totalFee: 35,
            amountReceived: 5000,
            updatedAt: CarbonImmutable::now(),
            sanitizedPayload: ['status' => 'paid', 'ref_id' => $topUp->merchant_ref, 'trx_reference' => $topUp->provider_reference],
        );
        $processor = app(WijayaPayStoreBalanceTopUpCallbackProcessor::class);
        $this->assertNotNull($processor->processCallback($callback)->processed_at);
        $this->assertNotNull($processor->processCallback($callback)->processed_at);
        app(StoreBalanceTopUpService::class)->reconcile($topUp->fresh());
        $this->assertSame(1, StoreBalanceEntry::query()->count());
        $this->assertSame('manual', $topUp->fresh()->settled_provider);
        $this->assertNotNull($topUp->fresh()->manual_alternative_provider_paid_at);
        $this->assertSame(['manual', 'wijayapay'], DB::table('external_money_receipts')->where('target_id', (string) $topUp->id)->orderBy('provider')->pluck('provider')->all());
        $this->assertTrue($topUp->fresh()->isEligibleForOwnerAttention());
        $this->assertNotNull($original->fresh()->telegram_message_deleted_at);
        $this->assertSame(1, app(StoreBalanceTopUpOutbound::class)->recoverMissingOwnerAlerts());
    }

    public function test_original_provider_payment_before_admin_approval_preserves_automatic_source_and_records_second_rail_review(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $this->statusProvider($topUp, WijayaPayStatus::Pending, WijayaPayStatus::Paid);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->submitProof($customer);
        $service->reconcile($topUp->fresh());
        $operator = Customer::factory()->create(['telegram_id' => self::ADMIN]);
        $decision = app(ManualStoreBalanceTopUpConfirmationService::class)->approve($topUp, $operator, self::ADMIN);
        $this->assertSame(ManualStoreBalanceTopUpDecision::AlreadyConfirmed, $decision);
        $this->assertSame('wijayapay', $topUp->fresh()->settled_provider);
        $this->assertNotNull($topUp->fresh()->manual_alternative_provider_paid_at);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
    }

    public function test_a_queued_original_instruction_cannot_publish_after_the_manual_switch(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $original = app(TelegramOutboundEffectService::class)->recordBalanceTopUpInstructions($topUp);
        $this->statusProvider($topUp, WijayaPayStatus::Pending);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        app(StoreBalanceTopUpOutbound::class)->handoffInstructions($topUp->fresh());
        $photosBefore = Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count();

        app(BalanceTopUpInstructionDeliveryPipeline::class)->deliver((string) $topUp->id, (string) $original->id);

        $this->assertSame(TelegramOutboundEffectState::Failed, $original->fresh()->state);
        $this->assertSame($photosBefore, Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/sendPhoto'))->count());
        $this->assertSame(1, TelegramOutboundEffect::query()
            ->where('source_type', StoreBalanceTopUp::class)->where('source_id', (string) $topUp->id)
            ->where('effect_type', TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP)
            ->where('state', TelegramOutboundEffectState::Sent->value)->count());
    }

    public function test_missing_manual_admin_and_ambiguous_create_are_not_switchable(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $service = app(StoreBalanceTopUpService::class);
        $topUp->forceFill(['status' => StoreBalanceTopUpStatus::Failed, 'provider_create_state' => 'outcome_unknown'])->save();
        $this->assertFalse($service->canOfferManualAlternative($topUp));
        $this->assertFalse($service->requestManualAlternative($customer, self::BUYER, $topUp));
        $topUp->forceFill(['status' => StoreBalanceTopUpStatus::Payable, 'provider_create_state' => 'created'])->save();
        ManualPaymentSetting::query()->sole()->forceFill(['admin_telegram_chat_id' => null])->save();
        $this->assertFalse($service->canOfferManualAlternative($topUp));
        $this->assertNull($topUp->fresh()->manual_alternative_at);
    }

    public function test_a_rejected_manual_alternative_cannot_be_approved_but_late_original_money_still_settles(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $this->statusProvider($topUp, WijayaPayStatus::Pending, WijayaPayStatus::Paid);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->submitProof($customer);
        $operator = Customer::factory()->create(['telegram_id' => self::ADMIN]);
        $confirmation = app(ManualStoreBalanceTopUpConfirmationService::class);
        $this->assertSame(ManualStoreBalanceTopUpDecision::Rejected, $confirmation->reject($topUp, $operator, self::ADMIN));
        $this->assertSame(ManualStoreBalanceTopUpDecision::AlreadyRejected, $confirmation->approve($topUp, $operator, self::ADMIN));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
        $this->travel(20)->minutes();
        $service->reconcile($topUp->fresh());
        $this->assertSame('wijayapay', $topUp->fresh()->settled_provider);
        $this->assertSame(1, StoreBalanceEntry::query()->count());
    }

    public function test_expired_manual_alternative_proof_is_not_an_authorization_to_credit(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $this->statusProvider($topUp, WijayaPayStatus::Pending);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->submitProof($customer);
        $this->travel(20)->minutes();
        $operator = Customer::factory()->create(['telegram_id' => self::ADMIN]);
        $this->assertSame(ManualStoreBalanceTopUpDecision::Expired, app(ManualStoreBalanceTopUpConfirmationService::class)->approve($topUp, $operator, self::ADMIN));
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_rollback_refuses_to_erase_manual_alternative_financial_provenance(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $topUp->forceFill(['manual_alternative_at' => now(), 'settled_provider' => 'manual'])->save();
        $migration = require database_path('migrations/2026_11_10_000002_add_manual_alternative_to_store_balance_top_ups.php');
        try {
            $migration->down();
            $this->fail('Rollback must retain financial provenance.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('provenance', $exception->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('store_balance_top_ups', 'manual_alternative_at'));
        $this->assertSame('manual', $topUp->fresh()->settled_provider);
        $this->assertSame('wijayapay', $topUp->fresh()->provider);
    }

    public function test_missing_static_manual_qr_is_not_offered(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        $path = public_path();
        $this->app->usePublicPath(sys_get_temp_dir().'/missing-topup-manual-qr-'.bin2hex(random_bytes(8)));
        try {
            $this->assertFalse(app(StoreBalanceTopUpService::class)->canOfferManualAlternative($topUp));
        } finally {
            $this->app->usePublicPath($path);
        }
    }

    public function test_sakurupiah_pending_invoice_switches_on_its_original_reference(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        config()->set('services.sakurupiah', array_replace(config('services.sakurupiah', []), [
            'sandbox_enabled' => true,
            'sandbox_base_url' => 'https://sakurupiah.id/api-sanbox/',
            'sandbox_api_id' => 'test-api-id', 'sandbox_api_key' => 'test-api-key',
            'sandbox_callback_url' => 'https://store.example.test/api/webhook/sakurupiah',
            'sandbox_return_url' => 'https://store.example.test/shop/orders',
            'sandbox_allowed_hosts' => ['sakurupiah.id'],
            'sandbox_callback_allowed_hosts' => ['store.example.test'],
            'sandbox_return_allowed_hosts' => ['store.example.test'],
        ]));
        $topUp->forceFill(['provider' => 'sakurupiah', 'sakurupiah_channel' => 'QRISC'])->save();
        $adapter = Mockery::mock(SakurupiahProviderAdapter::class);
        $adapter->shouldReceive('transactionStatus')->once()->with((string) $topUp->provider_reference)
            ->andReturn(new SakurupiahStatus(
                trxId: (string) $topUp->provider_reference,
                status: SakurupiahTransactionStatus::Pending,
                merchantRef: (string) $topUp->merchant_ref,
                paymentCode: 'QRISC',
                amount: SakurupiahAmount::from(5000),
                total: SakurupiahAmount::from(5035),
                currency: 'IDR',
            ));
        $adapter->shouldNotReceive('createInvoice');
        $this->app->instance(SakurupiahProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp));
        $this->assertSame('sakurupiah', $topUp->fresh()->provider);
        $this->assertSame('WPTRX-ALT-'.$topUp->store_balance_funding_intent_id, $topUp->fresh()->provider_reference);
        $this->assertSame(5035, $topUp->fresh()->provider_total_payment);
        $this->assertSame(5000, $service->presentation($topUp->fresh())->payable()->totalAmount());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    public function test_klikqris_pending_invoice_switches_only_after_matching_signed_status(): void
    {
        [$customer, $root, $topUp] = $this->topUp();
        KlikQrisSandboxScenarios::configureReadySandbox();
        $payload = ['data' => [
            'order_id' => $topUp->merchant_ref, 'amount' => 5000, 'total_amount' => 5035,
            'status' => 'PENDING', 'signature' => 'signed-topup-alternative',
            'qris_url' => 'https://klikqris.com/storage/sandbox/qris_balance_top_up.png',
            'expired_at' => $topUp->provider_expires_at->toISOString(),
            'provider_reference' => $topUp->provider_reference,
        ]];
        $topUp->forceFill([
            'provider' => 'klikqris', 'provider_signature' => 'signed-topup-alternative',
            'provider_payload' => $payload,
            'provider_qris_url' => $payload['data']['qris_url'],
        ])->save();
        $adapter = Mockery::mock(KlikQrisProviderAdapter::class.', '.KlikQrisTopUpProviderAdapter::class);
        $adapter->shouldReceive('topUpTransactionStatus')->once()
            ->andReturn(KlikQrisTransaction::fromStatusPayload($payload));
        $adapter->shouldNotReceive('createTopUpTransaction');
        $this->app->instance(KlikQrisProviderAdapter::class, $adapter);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
        $service = app(StoreBalanceTopUpService::class);
        $service->requestManualAlternative($customer, self::BUYER, $topUp);
        $this->assertTrue($service->confirmManualAlternative($customer, self::BUYER, $topUp), json_encode($topUp->fresh()->only(['status', 'provider_create_state', 'provider_create_last_error'])));
        $this->assertSame('klikqris', $topUp->fresh()->provider);
        $this->assertSame('signed-topup-alternative', $topUp->fresh()->providerSignature());
        $this->assertSame(5035, $topUp->fresh()->provider_total_payment);
        $this->assertSame(5000, $service->presentation($topUp->fresh())->payable()->totalAmount());
        $this->assertSame(0, StoreBalanceEntry::query()->count());
    }

    private function topUp(): array
    {
        $customer = Customer::factory()->create(['telegram_id' => self::BUYER, 'locale' => 'en']);
        $root = app(TelegramNavigationRootService::class)->establish($customer, self::BUYER, app(TelegramMenuIntents::class)->addBalanceSurface($customer));
        $intent = StoreBalanceFundingIntent::query()->create([
            'customer_id' => $customer->id, 'telegram_navigation_root_id' => $root->id,
            'chat_id' => self::BUYER, 'canonical_message_id' => $root->message_id,
            'policy_version' => 1, 'amount' => 5000, 'nonce' => bin2hex(random_bytes(16)),
            'status' => StoreBalanceFundingIntentStatus::Confirmed, 'expires_at' => now()->addMinutes(15), 'claimed_at' => now(),
        ]);
        $topUp = StoreBalanceTopUp::query()->create([
            'customer_id' => $customer->id, 'store_balance_funding_intent_id' => $intent->id,
            'telegram_navigation_root_id' => $root->id, 'chat_id' => self::BUYER,
            'canonical_message_id' => $root->message_id, 'locale' => 'en', 'provider' => 'wijayapay',
            'payment_setting_id' => ManualPaymentSetting::query()->sole()->id, 'payment_provider_version' => 1,
            'merchant_ref' => 'BAL-ALT-'.$intent->id, 'idempotency_key' => 'balance-top-up:intent:'.$intent->id,
            'currency' => 'IDR', 'amount' => 5000, 'status' => StoreBalanceTopUpStatus::Payable,
            'provider_reference' => 'WPTRX-ALT-'.$intent->id, 'provider_payload' => ['status_pembayaran' => 'pending'],
            'provider_qris_url' => 'https://wijayapay.com/qris/topup.png', 'provider_fee' => 35,
            'provider_total_payment' => 5035, 'provider_payment_method' => 'QRIS',
            'provider_created_at' => now()->subMinute(), 'provider_expires_at' => now()->addMinutes(15),
            'expires_at' => now()->addMinutes(15), 'provider_create_state' => 'created', 'provider_create_attempted_at' => now()->subMinute(),
        ]);

        return [$customer, $root, $topUp];
    }

    private function sentInstruction(StoreBalanceTopUp $topUp): TelegramOutboundEffect
    {
        $effect = app(TelegramOutboundEffectService::class)->recordBalanceTopUpInstructions($topUp);
        $effect->forceFill(['state' => TelegramOutboundEffectState::Sent, 'sent_at' => now(), 'telegram_message_id' => '123450'])->save();

        return $effect;
    }

    private function statusProvider(StoreBalanceTopUp $topUp, WijayaPayStatus ...$statuses): void
    {
        $baseline = DB::transactionLevel();
        $responses = array_map(fn (WijayaPayStatus $status) => new WijayaPayStatusResponse(
            refId: (string) $topUp->merchant_ref, trxReference: (string) $topUp->provider_reference,
            paymentMethod: 'QRIS', status: $status, totalBayar: 5035,
            totalDibayar: $status === WijayaPayStatus::Paid ? 5035 : null,
            totalFee: 35, amountReceived: 5000, expiresAt: CarbonImmutable::instance($topUp->provider_expires_at),
            updatedAt: CarbonImmutable::now(), sanitizedPayload: ['status_pembayaran' => $status->value],
        ), $statuses);
        $provider = Mockery::mock(WijayaPayProviderAdapter::class);
        $provider->shouldReceive('transactionStatus')->times(count($responses))->with((string) $topUp->merchant_ref)
            ->andReturnUsing(function () use (&$responses, $baseline) {
                $this->assertSame($baseline, DB::transactionLevel(), 'Provider HTTP must run outside monetary locks.');

                return array_shift($responses);
            });
        $provider->shouldNotReceive('createInvoice');
        $this->app->instance(WijayaPayProviderAdapter::class, $provider);
        $this->app->forgetInstance(StoreBalanceTopUpLifecycleResolver::class);
    }

    private function customerAction(Customer $customer, TelegramNavigationRoot $root, StoreBalanceTopUp $topUp, string $action): void
    {
        app(TelegramBalanceFundingCallbackHandler::class)->handle(new TelegramCallbackContext(
            $customer, (string) $customer->telegram_id, 'alternative-'.$action,
            StoreBalanceTopUpService::actionCallback($action, $topUp->id), messageId: (int) $root->message_id,
        ));
    }

    private function submitProof(Customer $customer): void
    {
        $this->assertTrue(app(TelegramManualTopUpIntents::class)->submitTopUpProof($customer, self::BUYER, [
            'photo' => [['file_id' => 'small-proof'], ['file_id' => 'large-proof']],
        ]));
    }

    private function providerSnapshot(StoreBalanceTopUp $topUp): array
    {
        return $topUp->only([
            'provider', 'payment_setting_id', 'payment_provider_version', 'merchant_ref', 'idempotency_key',
            'provider_reference', 'provider_payload', 'provider_qris_url', 'provider_fee', 'provider_total_payment',
            'provider_payment_method', 'provider_create_state',
        ]);
    }
}
