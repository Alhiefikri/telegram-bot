<?php

namespace App\Providers;

use App\Bootstrap\ApplicationRateLimiterRegistrar;
use App\Bootstrap\ProductionConfigurationValidator;
use App\Models\BscPaymentReceiver;
use App\Models\Category;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductQuantityPriceTier;
use App\Models\StorefrontSetting;
use App\Observers\ProductAuditObserver;
use App\Observers\StorefrontCacheInvalidationObserver;
use App\Services\Admin\AdminAccessAuthorizer;
use App\Services\Admin\AdminAccessAuthorizerInterface;
use App\Services\Audit\AuditContext;
use App\Services\Audit\HttpAuditContext;
use App\Services\Bsc\BscRpcClient;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscVerificationRpcClientAdapter;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Bsc\BscWalletOwnershipVerifier;
use App\Services\Bsc\BscWalletOwnershipVerifierInterface;
use App\Services\Checkout\BinanceCheckoutPaymentPreparation;
use App\Services\Checkout\BscCheckoutPaymentPreparation;
use App\Services\Checkout\CheckoutPaymentPreparationResolver;
use App\Services\Checkout\KlikQrisCheckoutPaymentPreparation;
use App\Services\Checkout\ManualQrisCheckoutPaymentPreparation;
use App\Services\Checkout\SakurupiahCheckoutPaymentPreparation;
use App\Services\Checkout\StoreBalanceCheckoutPaymentPreparation;
use App\Services\Checkout\UsdtBalanceCheckoutPaymentPreparation;
use App\Services\EloquentInventoryLedger;
use App\Services\InventoryLedger;
use App\Services\KlikQris\KlikQrisClient;
use App\Services\KlikQris\KlikQrisProviderAdapter;
use App\Services\KlikQris\KlikQrisTopUpProviderAdapter;
use App\Services\OrderLifecycle;
use App\Services\OrderStateMachine;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\Pakasir\PakasirClient;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Sakurupiah\SakurupiahClient;
use App\Services\Sakurupiah\SakurupiahProviderAdapter;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\StoreBalance\TopUp\KlikQrisStoreBalanceTopUpLifecycle;
use App\Services\StoreBalance\TopUp\ManualStoreBalanceTopUpLifecycle;
use App\Services\StoreBalance\TopUp\SakurupiahStoreBalanceTopUpLifecycle;
use App\Services\StoreBalance\TopUp\StoreBalanceTopUpLifecycleResolver;
use App\Services\Telegram\Callbacks\TelegramAdminPaymentCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramAdminStatsCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBalanceActivityCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBalanceFundingCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBinanceVerifyCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBroadcastCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBscVerifyCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCartCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCatalogCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCheckoutCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCheckoutMaintenanceCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramHomeCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramIconCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramLocaleCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramOrderCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramOrderTxtCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramPaymentProofCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramReceiptActionCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramUsdtFundingCallbackHandler;
use App\Services\Telegram\FakeTelegramBotClient;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackAckState;
use App\Services\Telegram\TelegramCallbackHandlerPipeline;
use App\Services\Telegram\TelegramCheckoutPaymentIntentSelector;
use App\Services\Telegram\TelegramConfiguredCheckoutPaymentIntentSelector;
use App\Services\UsdtFunding\LocalBscFundingReadiness;
use App\Services\UsdtFunding\UsdtFundingRailReadinessRegistry;
use App\Services\UsdtReceivers\UsdtDepositReceiverProvisioners;
use App\Services\UsdtTopUps\BscUsdtTopUpRail;
use App\Services\UsdtTopUps\UsdtTopUpRail;
use App\Services\UsdtTreasury\UsdtTreasuryAssetRegistry;
use App\Support\DogfoodTiming;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(InventoryLedger::class, EloquentInventoryLedger::class);
        $this->app->bind(OrderLifecycle::class, OrderStateMachine::class);
        $this->app->bind(AuditContext::class, HttpAuditContext::class);
        $this->app->bind(AdminAccessAuthorizerInterface::class, AdminAccessAuthorizer::class);
        $this->app->bind(BscRpcClientInterface::class, BscRpcClient::class);
        $this->app->bind(BscVerificationRpcClientInterface::class, function ($app): BscVerificationRpcClientInterface {
            $client = $app->make(BscRpcClientInterface::class);

            return $client instanceof BscVerificationRpcClientInterface
                ? $client
                : new BscVerificationRpcClientAdapter($client);
        });
        $this->app->bind(UsdtTopUpRail::class, BscUsdtTopUpRail::class);
        $this->app->bind(BscWalletOwnershipVerifierInterface::class, BscWalletOwnershipVerifier::class);
        $this->app->bind(KlikQrisProviderAdapter::class, KlikQrisClient::class);
        $this->app->bind(KlikQrisTopUpProviderAdapter::class, function ($app): KlikQrisTopUpProviderAdapter {
            $provider = $app->make(KlikQrisProviderAdapter::class);
            if (! $provider instanceof KlikQrisTopUpProviderAdapter) {
                throw new \RuntimeException('The configured KlikQRIS provider does not support Store Balance top-ups.');
            }

            return $provider;
        });
        $this->app->bind(PakasirProviderAdapter::class, PakasirClient::class);
        $this->app->bind(SakurupiahProviderAdapter::class, SakurupiahClient::class);
        $this->app->singleton(SakurupiahProviderReadiness::class);
        // USDT treasury asset providers are approved adapters: an implementation
        // may be tagged only after the H1 custody/capacity/provider gates pass.
        // Until then the registry is intentionally empty and reconciliation
        // reports missing custody input instead of any fabricated backing.
        $this->app->singleton(UsdtTreasuryAssetRegistry::class, fn ($app): UsdtTreasuryAssetRegistry => new UsdtTreasuryAssetRegistry(
            $app->tagged('usdt-treasury-asset-providers'),
        ));
        // USDT deposit receiver provisioners are approved custody adapters: a
        // provider may be tagged only after the H1 custody/capacity gates pass.
        // Until then the registry stays empty and allocation fails closed
        // instead of falling back to a shared address.
        $this->app->singleton(UsdtDepositReceiverProvisioners::class, fn ($app): UsdtDepositReceiverProvisioners => new UsdtDepositReceiverProvisioners(
            $app->tagged('usdt-deposit-receiver-provisioners'),
        ));
        // Qualified production adapters remain gated. U5's technical-only BSC
        // path is available in disposable local/testing environments, without
        // replacing an explicitly registered readiness provider.
        $this->app->singleton(UsdtFundingRailReadinessRegistry::class, function ($app): UsdtFundingRailReadinessRegistry {
            $providers = iterator_to_array($app->tagged('usdt-funding-rail-readiness'));
            if ($app->environment(['local', 'testing'])
                && ! collect($providers)->contains(fn ($provider): bool => $provider->railKey() === 'bsc_usdt')) {
                $providers[] = $app->make(LocalBscFundingReadiness::class);
            }

            return new UsdtFundingRailReadinessRegistry($providers);
        });
        $this->app->singleton(CheckoutPaymentPreparationResolver::class, function ($app) {
            return new CheckoutPaymentPreparationResolver([
                ManualQrisCheckoutPaymentPreparation::IDENTITY => $app->make(ManualQrisCheckoutPaymentPreparation::class),
                KlikQrisCheckoutPaymentPreparation::IDENTITY => $app->make(KlikQrisCheckoutPaymentPreparation::class),
                BinanceCheckoutPaymentPreparation::IDENTITY => $app->make(BinanceCheckoutPaymentPreparation::class),
                BscCheckoutPaymentPreparation::IDENTITY => $app->make(BscCheckoutPaymentPreparation::class),
                SakurupiahCheckoutPaymentPreparation::IDENTITY => $app->make(SakurupiahCheckoutPaymentPreparation::class),
                StoreBalanceCheckoutPaymentPreparation::IDENTITY => $app->make(StoreBalanceCheckoutPaymentPreparation::class),
                UsdtBalanceCheckoutPaymentPreparation::IDENTITY => $app->make(UsdtBalanceCheckoutPaymentPreparation::class),
            ]);
        });
        $this->app->bind(TelegramBotClient::class, $this->telegramBotClientClass());
        $this->app->singleton(StoreBalanceTopUpLifecycleResolver::class, function ($app) {
            return new StoreBalanceTopUpLifecycleResolver([
                $app->make(KlikQrisStoreBalanceTopUpLifecycle::class),
                $app->make(ManualStoreBalanceTopUpLifecycle::class),
                $app->make(SakurupiahStoreBalanceTopUpLifecycle::class),
            ]);
        });
        $this->app->singleton(PaidInvoiceRenderer::class);
        $this->app->singleton(TelegramCallbackAckState::class);
        $this->app->bind(TelegramCheckoutPaymentIntentSelector::class, TelegramConfiguredCheckoutPaymentIntentSelector::class);
        $this->app->singleton(TelegramCallbackHandlerPipeline::class, fn ($app) => new TelegramCallbackHandlerPipeline([
            $app->make(TelegramLocaleCallbackHandler::class),
            $app->make(TelegramBalanceFundingCallbackHandler::class),
            $app->make(TelegramBalanceActivityCallbackHandler::class),
            $app->make(TelegramUsdtFundingCallbackHandler::class),
            $app->make(TelegramHomeCallbackHandler::class),
            $app->make(TelegramAdminPaymentCallbackHandler::class),
            $app->make(TelegramBroadcastCallbackHandler::class),
            $app->make(TelegramAdminStatsCallbackHandler::class),
            $app->make(TelegramIconCallbackHandler::class),
            $app->make(TelegramCatalogCallbackHandler::class),
            $app->make(TelegramOrderCallbackHandler::class),
            $app->make(TelegramOrderTxtCallbackHandler::class),
            $app->make(TelegramReceiptActionCallbackHandler::class),
            $app->make(TelegramCartCallbackHandler::class),
            $app->make(TelegramCheckoutCallbackHandler::class),
            $app->make(TelegramCheckoutMaintenanceCallbackHandler::class),
            $app->make(TelegramBscVerifyCallbackHandler::class),
            $app->make(TelegramBinanceVerifyCallbackHandler::class),
            $app->make(TelegramPaymentProofCallbackHandler::class),
        ]));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->make(ProductionConfigurationValidator::class)->assertValid();
        $this->configureUrlGeneration();
        Vite::createAssetPathsUsing(fn (string $path, ?bool $secure): string => '/'.ltrim($path, '/'));

        Product::observe(ProductAuditObserver::class);
        Product::observe(StorefrontCacheInvalidationObserver::class);
        ProductFamily::observe(StorefrontCacheInvalidationObserver::class);
        Category::observe(StorefrontCacheInvalidationObserver::class);
        ManualPaymentSetting::observe(StorefrontCacheInvalidationObserver::class);
        BscPaymentReceiver::observe(StorefrontCacheInvalidationObserver::class);
        Order::observe(StorefrontCacheInvalidationObserver::class);
        OrderItem::observe(StorefrontCacheInvalidationObserver::class);
        ProductQuantityPriceTier::observe(StorefrontCacheInvalidationObserver::class);
        StorefrontSetting::observe(StorefrontCacheInvalidationObserver::class);

        DB::listen(fn ($query) => DogfoodTiming::recordQuery($query));

        $this->app->make(ApplicationRateLimiterRegistrar::class)->register();
    }

    private function configureUrlGeneration(): void
    {
        if (in_array(config('app.env'), ['local', 'testing'], true) || $this->isLoopbackHttpRequest()) {
            return;
        }

        $appUrl = rtrim((string) config('app.url'), '/');

        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        if ((bool) config('app.force_https')) {
            URL::forceScheme('https');
        }
    }

    private function telegramBotClientClass(): string
    {
        if (($this->app->environment('testing') && ! $this->isPhpUnitProcess())
            || (bool) config('services.dogfood_timing.fake_telegram_outbound')) {
            return FakeTelegramBotClient::class;
        }

        return TelegramBotClient::class;
    }

    private function isPhpUnitProcess(): bool
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');

        return defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || str_ends_with($script, 'phpunit');
    }

    private function isLoopbackHttpRequest(): bool
    {
        $request = request();

        if ($request->isSecure()) {
            return false;
        }

        return in_array(trim($request->getHost(), '[]'), ['localhost', '127.0.0.1', '::1'], true);
    }
}
