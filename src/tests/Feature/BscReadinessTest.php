<?php

namespace Tests\Feature;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use App\Models\Product;
use App\Services\Bsc\BscReadiness;
use App\Services\Bsc\BscRpcClientInterface;
use App\Services\Bsc\BscRpcException;
use App\Services\Bsc\BscVerificationRpcClientInterface;
use App\Services\Telegram\TelegramCheckoutIntents;
use App\Storefront\StorefrontProductPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class BscReadinessTest extends TestCase
{
    use RefreshDatabase;

    private const CONTRACT = '0x55d398326f99059ff775485246999027b3197955';

    private object $rpc;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.bsc.rpc_url', 'https://bsc-rpc.test');
        config()->set('services.bsc.chain_id', 56);
        config()->set('services.bsc.usdt_contract', self::CONTRACT);
        config()->set('services.bsc.expected_token_decimals', 18);
        config()->set('services.bsc.readiness_cache_seconds', 60);

        $this->rpc = new class implements BscVerificationRpcClientInterface
        {
            public int $calls = 0;

            public bool $logsSupported = true;

            public bool $metadataAvailable = true;

            public int $chainIdValue = 56;

            public int $tokenDecimalsValue = 18;

            public function chainId(): int
            {
                $this->calls++;

                if (! $this->metadataAvailable) {
                    throw new BscRpcException('RPC is unavailable.', retryable: true);
                }

                return $this->chainIdValue;
            }

            public function contractCode(string $contract): string
            {
                $this->calls++;

                if (! $this->metadataAvailable) {
                    throw new BscRpcException('RPC is unavailable.', retryable: true);
                }

                return '0x60016001';
            }

            public function tokenDecimals(string $contract): int
            {
                $this->calls++;

                if (! $this->metadataAvailable) {
                    throw new BscRpcException('RPC is unavailable.', retryable: true);
                }

                return $this->tokenDecimalsValue;
            }

            public function isValidErc1271Signature(string $contract, string $messageHashHex, string $signature): bool
            {
                return true;
            }

            public function transactionReceipt(string $transactionHash): ?array
            {
                throw new BscRpcException('Not used by readiness.');
            }

            public function blockByNumber(string|int $blockNumber): ?array
            {
                throw new BscRpcException('Not used by readiness.');
            }

            public function transferLogs(string $contract, string|int $fromBlock, string|int $toBlock, ?string $receiver = null): array
            {
                $this->calls++;

                if (! $this->logsSupported) {
                    throw new BscRpcException('eth_getLogs is not supported.', retryable: false);
                }

                return [];
            }
        };

        app()->instance(BscRpcClientInterface::class, $this->rpc);

        ManualPaymentSetting::create([
            'payment_mode' => 'manual',
            'is_active' => true,
            'bsc_payment_enabled' => true,
        ]);

        BscPaymentReceiver::factory()->manuallyVerified()->active()->create([
            'token_contract' => self::CONTRACT,
        ]);
    }

    public function test_readiness_fails_when_the_rpc_rejects_transfer_log_scanning(): void
    {
        $this->rpc->logsSupported = false;

        $report = app(BscReadiness::class)->check();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('BSC_ETH_GET_LOGS', $report->invalid);
    }

    public function test_readiness_fails_when_the_rpc_is_down(): void
    {
        $this->rpc->metadataAvailable = false;

        $report = app(BscReadiness::class)->check();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('BSC_RPC', $report->invalid);
    }

    public function test_readiness_fails_when_token_metadata_is_invalid(): void
    {
        $this->rpc->tokenDecimalsValue = 6;

        $report = app(BscReadiness::class)->check();

        $this->assertFalse($report->isReady());
        $this->assertArrayHasKey('BSC_USDT_DECIMALS', $report->invalid);
    }

    public function test_configured_indexed_source_can_replace_rpc_log_scanning(): void
    {
        $this->rpc->logsSupported = false;
        config()->set('services.bsc.indexed_source', 'https://indexer.test/transfers');

        $report = app(BscReadiness::class)->check();

        $this->assertTrue($report->isReady());
    }

    public function test_readiness_is_cached_and_invalidated_after_setting_and_receiver_mutations(): void
    {
        $readiness = app(BscReadiness::class);

        $readiness->check();
        $readiness->check();
        $this->assertSame(4, $this->rpc->calls);

        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => false]);
        $readiness->check();
        $this->assertSame(4, $this->rpc->calls);

        ManualPaymentSetting::current()->update(['bsc_payment_enabled' => true]);
        BscPaymentReceiver::query()->firstOrFail()->update(['label' => 'Rotated receiver']);
        $readiness->check();

        $this->assertSame(8, $this->rpc->calls);
    }

    public function test_readiness_cache_expires_after_its_short_ttl(): void
    {
        $now = Carbon::now();
        Carbon::setTestNow($now);

        try {
            app(BscReadiness::class)->check();
            Carbon::setTestNow($now->copy()->addSeconds(61));
            app(BscReadiness::class)->check();
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(8, $this->rpc->calls);
    }

    public function test_customer_surfaces_hide_bsc_when_the_shared_readiness_result_is_unready(): void
    {
        $this->rpc->logsSupported = false;
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $this->assertFalse(app(TelegramCheckoutIntents::class)->bscRailSelectable());
        $this->assertNull(
            app(StorefrontProductPresenter::class)->present($product)->checkoutChoice('website_usdt_bep20'),
        );
        $this->assertNull(
            app(StorefrontProductPresenter::class)->present($product)->checkoutChoice('website_binance_pay'),
        );
        $this->assertSame(4, $this->rpc->calls);
    }

    public function test_storefront_keeps_binance_pay_id_without_describing_unready_bsc(): void
    {
        $this->rpc->logsSupported = false;
        config()->set('services.binance.pay_id', '987654321');
        ManualPaymentSetting::current()->update(['binance_pay_enabled' => true]);
        $product = Product::factory()->create([
            'stock' => 1,
            'reserved_stock' => 0,
            'usdt_price_amount' => 2_500_000,
        ]);

        $presentation = app(StorefrontProductPresenter::class)->present($product);

        $this->assertNotNull($presentation->checkoutChoice('website_binance_pay'));
        $this->assertNull($presentation->checkoutChoice('website_usdt_bep20'));
        $this->assertStringNotContainsString('BSC', (string) $presentation->checkoutChoice('website_binance_pay')?->description);
    }
}
