<?php

namespace Tests\Feature;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\KlikQris\KlikQrisCheckoutSelector;
use App\Storefront\StorefrontPresenter;
use App\Storefront\StorefrontProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StorefrontCatalogBoundaryRpc implements BscVerificationRpcClientInterface
{
    public int $calls = 0;

    public function chainId(): int
    {
        $this->calls++;

        return 56;
    }

    public function contractCode(string $contract): string
    {
        $this->calls++;

        return '0x60016001';
    }

    public function tokenDecimals(string $contract): int
    {
        $this->calls++;

        return 18;
    }

    public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
    {
        return true;
    }

    public function transactionReceipt(string $transactionHash): ?array
    {
        return null;
    }

    public function blockByNumber(string|int $blockNumber): ?array
    {
        return null;
    }
}

final class StorefrontCatalogDiscoveryBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private const BSC_CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    protected function setUp(): void
    {
        parent::setUp();

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
        ]);
    }

    public function test_home_shop_and_family_discovery_do_not_resolve_checkout_dependencies(): void
    {
        $family = ProductFamily::factory()->create(['slug' => 'catalog-boundary', 'name' => 'Catalog Boundary']);
        Product::factory()->create([
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'name' => 'Catalog Boundary SKU',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);
        Product::factory()->create([
            'name' => 'Catalog Boundary Standalone',
            'stock' => 2,
            'reserved_stock' => 0,
        ]);

        $resolved = [];
        $this->watchCheckoutDependencyResolution($resolved);

        $this->get(route('storefront.home'))->assertOk();
        $this->get(route('storefront.shop.index'))->assertOk();
        $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))->assertOk();

        $this->assertSame([], array_values(array_unique(array_intersect($resolved, $this->checkoutDependencyClasses()))));
    }

    public function test_direct_checkout_page_is_the_first_discovery_boundary_that_resolves_checkout_readiness(): void
    {
        $rpc = $this->configureBscReadiness();
        $setting = ManualPaymentSetting::current();
        $setting->update(['bsc_payment_enabled' => true]);
        BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::BSC_CONTRACT,
        ]);
        $family = ProductFamily::factory()->create(['slug' => 'lazy-boundary', 'name' => 'Lazy Boundary']);
        $product = Product::factory()->create([
            'product_family_id' => $family->id,
            'family_sort_order' => 1,
            'name' => 'Lazy Boundary SKU',
            'stock' => 2,
            'reserved_stock' => 0,
            'usdt_price_amount' => 1_500_000,
        ]);

        $resolved = [];
        $this->watchCheckoutDependencyResolution($resolved);

        $this->get(route('storefront.home'))->assertOk();
        $this->get(route('storefront.shop.index'))->assertOk();
        $this->get(route('storefront.product-families.show', ['productFamily' => $family->slug]))->assertOk();

        $this->assertSame([], array_values(array_unique(array_intersect($resolved, $this->checkoutDependencyClasses()))));
        $this->assertSame(0, $rpc->calls);

        $this->get(route('storefront.shop.checkout.product', ['product' => $product]))
            ->assertOk()
            ->assertSee('Lazy Boundary SKU');

        $this->assertContains(StorefrontProductPresenter::class, $resolved);
        $this->assertContains(BscReadiness::class, $resolved);
        $this->assertContains(KlikQrisCheckoutSelector::class, $resolved);
        $this->assertSame(0, $rpc->calls);
    }

    /**
     * @param  list<string>  $resolved
     */
    private function watchCheckoutDependencyResolution(array &$resolved): void
    {
        foreach ($this->checkoutDependencyClasses() as $abstract) {
            $this->app->resolving($abstract, function () use (&$resolved, $abstract): void {
                $resolved[] = $abstract;
            });
        }
    }

    /** @return list<class-string> */
    private function checkoutDependencyClasses(): array
    {
        return [
            StorefrontPresenter::class,
            StorefrontProductPresenter::class,
            BscReadiness::class,
            KlikQrisCheckoutSelector::class,
        ];
    }

    private function configureBscReadiness(): StorefrontCatalogBoundaryRpc
    {
        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::BSC_CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.bsc.readiness_cache_seconds', 60);

        $rpc = new StorefrontCatalogBoundaryRpc;
        $this->app->instance(BscRpcClientInterface::class, $rpc);

        return $rpc;
    }
}
