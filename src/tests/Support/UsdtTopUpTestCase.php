<?php

namespace Tests\Support;

use App\Enums\TelegramNavigationMediaKind;
use App\Enums\TelegramNavigationScreen;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Models\User;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramUsdtFundingActions;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use App\Services\UsdtTopUps\UsdtTopUpPreparation;
use App\Services\UsdtTopUps\UsdtTopUpSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

abstract class UsdtTopUpTestCase extends TestCase
{
    protected Customer $customer;

    protected CustomerUsdtTopUp $target;

    protected BscUsdtRailRpcFixture $rpc;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-10-06 08:00:00 UTC'));
        Http::preventStrayRequests();
        Queue::fake();
        $this->rpc = new BscUsdtRailRpcFixture;
        $this->app->instance(BscVerificationRpcClientInterface::class, $this->rpc);
        $this->customer = Customer::factory()->create(['telegram_id' => 951001]);
        $owner = User::factory()->create(['role' => UserRole::Owner]);
        TelegramNavigationRoot::query()->create([
            'customer_id' => $this->customer->id, 'chat_id' => '951001', 'message_id' => 100,
            'media_kind' => TelegramNavigationMediaKind::Text, 'screen' => TelegramNavigationScreen::Home,
            'locale' => 'id', 'generation' => 1,
        ]);
        ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual', 'bsc_min_confirmations' => 3, 'bsc_payment_ttl_minutes' => 15]);
        CustomerUsdtFundingPolicy::current()->update(['funding_enabled' => true, 'version' => 1]);
        $registry = app(UsdtDepositReceiverRegistry::class);
        $registry->manualVerify($registry->register($this->customer, BscUsdtRailRpcFixture::ADDRESS, $owner)->receiver, true, $owner);
        $actions = app(TelegramUsdtFundingActions::class);
        $nonce = $actions->preview('951001', '951001', 'private', 100, '1')->intent->nonce;
        $this->target = $actions->callback('951001', '951001', 'private', 100, 'usdt:confirm:'.$nonce)->topUp;
        app(UsdtTopUpPreparation::class)->prepare($this->target->id);
        $this->rpc->timestamp = now()->timestamp + 5;
    }

    protected function settle(string $hash = BscUsdtRailRpcFixture::TX): void
    {
        $service = app(UsdtTopUpSettlement::class);
        $service->submit($this->customer, $this->target->id, $hash);
        $service->check($this->target->id);
    }
}
