<?php

namespace Tests\Unit\Services\Telegram;

use App\Models\Customer;
use App\Services\Telegram\Callbacks\TelegramAdminPaymentCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramAdminStatsCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramBroadcastCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCartCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCatalogCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCheckoutCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramCheckoutMaintenanceCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramLocaleCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramOrderCallbackHandler;
use App\Services\Telegram\Callbacks\TelegramPaymentProofCallbackHandler;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackHandlerPipeline;
use App\Services\Telegram\TelegramCallbackStage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramCallbackHandlerPipelineTest extends TestCase
{
    public function test_dispatch_uses_handlers_in_order_and_stops_after_first_successful_handler(): void
    {
        $calls = [];
        $pipeline = new TelegramCallbackHandlerPipeline([
            new RecordingTelegramCallbackHandler('first', true, false, $calls),
            new RecordingTelegramCallbackHandler('second', true, true, $calls),
            new RecordingTelegramCallbackHandler('third', true, true, $calls),
        ]);

        $handled = $pipeline->dispatch($this->context('checkout'));

        $this->assertTrue($handled);
        $this->assertSame([
            'first:supports',
            'first:handle',
            'second:supports',
            'second:handle',
        ], $calls);
    }

    public function test_dispatch_skips_unsupported_handlers_and_reports_unhandled_payloads(): void
    {
        $calls = [];
        $pipeline = new TelegramCallbackHandlerPipeline([
            new RecordingTelegramCallbackHandler('catalog', false, true, $calls),
            new RecordingTelegramCallbackHandler('orders', false, true, $calls),
        ]);

        $handled = $pipeline->dispatch($this->context('stale:payload'));

        $this->assertFalse($handled);
        $this->assertSame([
            'catalog:supports',
            'orders:supports',
        ], $calls);
    }

    public function test_dispatch_can_be_limited_to_a_callback_stage(): void
    {
        $calls = [];
        $pipeline = new TelegramCallbackHandlerPipeline([
            new RecordingTelegramCallbackHandler('before', true, true, $calls, TelegramCallbackStage::BeforePrivacy),
            new RecordingTelegramCallbackHandler('after', true, true, $calls, TelegramCallbackStage::Customer),
        ]);

        $handled = $pipeline->dispatch($this->context('checkout'), TelegramCallbackStage::Customer);

        $this->assertTrue($handled);
        $this->assertSame([
            'after:supports',
            'after:handle',
        ], $calls);
    }

    #[DataProvider('handlerFamilyProvider')]
    public function test_real_callback_families_own_matching_stage_and_non_private_policy(string $handlerClass, string $payload, TelegramCallbackStage $stage, bool $allowsNonPrivate): void
    {
        $handler = app($handlerClass);
        $context = $this->context($payload, chatType: 'group');

        $this->assertInstanceOf(TelegramCallbackHandler::class, $handler);
        $this->assertTrue($handler->supports($context));
        $this->assertSame($stage, $handler->stage());
        $this->assertSame($allowsNonPrivate, $handler->allowsNonPrivateChat($context));
    }

    public function test_real_pipeline_non_private_policy_is_handler_owned(): void
    {
        $pipeline = app(TelegramCallbackHandlerPipeline::class);

        $this->assertTrue($pipeline->allowsNonPrivateChat($this->context('product:1', chatType: 'group')));
        $this->assertFalse($pipeline->allowsNonPrivateChat($this->context('order:INV-SECRET', chatType: 'group')));
    }

    public static function handlerFamilyProvider(): array
    {
        return [
            'locale' => [TelegramLocaleCallbackHandler::class, 'locale:en', TelegramCallbackStage::BeforePrivacy, false],
            'admin payment' => [TelegramAdminPaymentCallbackHandler::class, 'admin:approve-payment:11111111-1111-1111-1111-111111111111', TelegramCallbackStage::BeforePrivacy, false],
            'broadcast' => [TelegramBroadcastCallbackHandler::class, 'broadcast:send', TelegramCallbackStage::Privileged, false],
            'admin stats' => [TelegramAdminStatsCallbackHandler::class, 'statistik_harian', TelegramCallbackStage::Privileged, false],
            'catalog' => [TelegramCatalogCallbackHandler::class, 'product:1', TelegramCallbackStage::Customer, true],
            'order' => [TelegramOrderCallbackHandler::class, 'order:INV-123', TelegramCallbackStage::Customer, false],
            'cart' => [TelegramCartCallbackHandler::class, 'detail:qty:+:1:token', TelegramCallbackStage::Customer, false],
            'checkout' => [TelegramCheckoutCallbackHandler::class, 'checkout', TelegramCallbackStage::Customer, false],
            'checkout maintenance status' => [TelegramCheckoutMaintenanceCallbackHandler::class, 'checkout-maintenance:status', TelegramCallbackStage::Customer, false],
            'payment proof' => [TelegramPaymentProofCallbackHandler::class, 'payment-proof:INV-123', TelegramCallbackStage::Customer, false],
        ];
    }

    private function context(string $data, string $chatType = 'private'): TelegramCallbackContext
    {
        return new TelegramCallbackContext(new Customer(['telegram_id' => 12345]), '12345', 'callback-id', $data, $chatType);
    }
}

class RecordingTelegramCallbackHandler implements TelegramCallbackHandler
{
    /**
     * @param  list<string>  $calls
     */
    public function __construct(
        private string $name,
        private bool $supports,
        private bool $handles,
        private array &$calls,
        private TelegramCallbackStage $stage = TelegramCallbackStage::Customer,
        private bool $allowsNonPrivate = false,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return $this->stage;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return $this->allowsNonPrivate;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        $this->calls[] = $this->name.':supports';

        return $this->supports;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        $this->calls[] = $this->name.':handle';

        return $this->handles;
    }
}
