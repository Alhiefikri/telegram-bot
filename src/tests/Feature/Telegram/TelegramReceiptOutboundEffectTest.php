<?php

namespace Tests\Feature\Telegram;

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\UserRole;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Jobs\SendTelegramReceipt;
use App\Jobs\SendTelegramReceiptActionPanel;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\CartService;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\OrderCheckoutService;
use App\Services\OrderStateMachine;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Telegram\AdminPaidOrderOutcome;
use App\Services\Telegram\CustomEmojiCapability;
use App\Services\Telegram\ReceiptDeliveryPipeline;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCredentialDeliveryRenderer;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramMessageChunker;
use App\Services\Telegram\TelegramMessageUnit;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectReviewService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramPaidOrderTxtExporter;
use App\Services\Telegram\TelegramProductNameRenderer;
use App\Services\Telegram\TelegramReceiptActionPanel;
use App\Services\Telegram\TelegramReceiptNotifier;
use App\Services\Telegram\TelegramReceiptPlan;
use App\Services\Telegram\TelegramSupportContact;
use App\Services\Telegram\TelegramUiIcons;
use App\Support\PaymentProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TelegramBotTestSupport;

class TelegramReceiptOutboundEffectTest extends TelegramBotTestSupport
{
    public function test_receipt_recovery_continues_after_one_orders_materialization_fails_and_logs_safely(): void
    {
        Queue::fake();
        $first = $this->multiProductStructuredReceiptOrder();
        [$second] = $this->paidSingleStructuredReceiptOrder('en', CredentialAccessMethod::None);
        $firstOrderId = (string) $first->getKey();
        $original = app(TelegramOutboundEffectService::class);
        $outboundEffects = Mockery::mock($original)->makePartial();
        $outboundEffects->shouldReceive('recordReceipt')
            ->twice()
            ->andReturnUsing(function (Order $order, ?Customer $customer = null) use ($firstOrderId, $original) {
                if ((string) $order->getKey() === $firstOrderId) {
                    throw new \RuntimeException('credential payload must not be logged');
                }

                return $original->recordReceipt($order, $customer);
            });
        app()->instance(TelegramOutboundEffectService::class, $outboundEffects);
        Log::spy();

        $this->artisan('telegram:recover-outbound')->assertSuccessful();

        $this->assertNull($original->forOrder(
            $firstOrderId,
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ));
        $this->assertNotNull($original->forOrder(
            (string) $second->getKey(),
            TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
        ));
        Log::shouldHaveReceived('warning')
            ->with('telegram.receipt_recovery_failed', Mockery::on(
                fn (array $context): bool => $context['order_id'] === $firstOrderId
                    && $context['stage'] === 'receipt_materialization'
                    && $context['error_code'] === 'receipt_materialization_failed'
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'credential payload must not be logged'),
            ))
            ->once();
    }

    public function test_paid_lifecycle_defers_receipt_planning_and_rendering_until_after_transaction(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 12346, 'locale' => 'en']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $transactionLevel = DB::transactionLevel();
        app()->instance(TelegramReceiptNotifier::class, $this->notifierObservingTransactionLevel($transactionLevel));

        app(OrderStateMachine::class)->applyPaymentPaid($order->payments()->sole());

        $this->assertSame(0, $order->telegramOutboundEffects()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->count());
        Queue::assertPushed(SendTelegramReceipt::class, 1);

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertNotNull($effect);
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->getKey()),
            $effect->effect_group_key,
        );
    }

    public function test_store_balance_checkout_defers_receipt_planning_and_rendering_until_after_transaction(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 12347, 'locale' => 'en']);
        $product = Product::factory()->create(['price_amount' => 15_000, 'stock' => 1, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        app(StoreBalanceLedger::class)->creditTopUp($customer, 25_000, 'test-top-up', 'test-top-up:receipt-lock');
        $transactionLevel = DB::transactionLevel();
        app()->instance(TelegramReceiptNotifier::class, $this->notifierObservingTransactionLevel($transactionLevel));

        $order = app(OrderCheckoutService::class)->checkout(
            $cart,
            CheckoutPaymentIntents::storeBalance(),
            'store-balance-receipt-lock',
        );

        Queue::assertPushed(SendTelegramReceipt::class, 1);
        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $this->assertNotNull($effect);
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->getKey()),
            $effect->effect_group_key,
        );
    }

    public function test_explicit_receipt_resend_prepares_before_order_locks(): void
    {
        $order = $this->multiProductStructuredReceiptOrder();
        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($effect);
        $effect->forceFill([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now(),
        ])->save();
        $transactionLevel = DB::transactionLevel();
        app()->instance(TelegramReceiptNotifier::class, $this->notifierObservingTransactionLevel($transactionLevel));

        $resend = app(TelegramOutboundEffectService::class)->resend(
            $effect->fresh(),
            User::factory()->create(['role' => UserRole::Owner]),
        );

        $this->assertSame(1, $resend->sequence);
        $this->assertStringContainsString(':resend:v2', (string) $resend->effect_group_key);
        $this->assertTrue($resend->outcome_metadata['duplicate_risk_acknowledged']);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
    }

    public function test_outbound_recovery_materializes_missing_receipt_after_paid_source_commit(): void
    {
        Queue::fake();
        $order = $this->multiProductStructuredReceiptOrder();
        $transactionLevel = DB::transactionLevel();
        app()->instance(TelegramReceiptNotifier::class, $this->notifierObservingTransactionLevel($transactionLevel));

        $this->assertSame(0, Artisan::call('telegram:recover-outbound'));

        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertCount(2, $effects);
        $effect = $effects->first();
        $this->assertNotNull($effect);
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->id),
            $effect->effect_group_key,
        );
        Queue::assertPushedOn(
            TelegramOutboundEffectService::RECEIPT_QUEUE,
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->orderId === (string) $order->id
                && $job->effectId === (string) $effect->getKey(),
        );
    }

    public function test_paid_lifecycle_materializes_one_idempotent_receipt_group(): void
    {
        Queue::fake();
        $customer = Customer::factory()->create(['telegram_id' => 12345, 'locale' => 'id']);
        $product = Product::factory()->create(['stock' => 1, 'reserved_stock' => 0]);
        $cart = app(CartService::class)->activeCartFor($customer);
        app(CartService::class)->addItem($cart, $product, 1);
        $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
        $payment = $order->payments()->sole();

        app(OrderStateMachine::class)->applyPaymentPaid($payment);
        app(OrderStateMachine::class)->applyPaymentPaid($payment->fresh());

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $replay = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $receiptEffects = $order->telegramOutboundEffects()
            ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
            ->orderBy('sequence')
            ->get();

        $this->assertNotNull($effect);
        $this->assertSame($effect->getKey(), $replay?->getKey());
        $this->assertCount(1, $receiptEffects);
        $this->assertSame(
            TelegramOutboundEffectService::receiptEffectGroupKey($order->id),
            $receiptEffects->first()->effect_group_key,
        );
        $this->assertSame(TelegramOutboundEffectState::Pending, $receiptEffects->first()->state);
        $this->assertSame('sendPhoto', $receiptEffects->first()->telegram_method);
        $this->assertSame('invoice_caption', $receiptEffects->first()->outcome_metadata['unit_role']);
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION,
            $receiptEffects->first()->template_version,
        );
        $this->assertStringNotContainsString('buyer@example', json_encode($receiptEffects->sole()->getAttributes(), JSON_THROW_ON_ERROR));
        Queue::assertPushedOn(
            TelegramOutboundEffectService::RECEIPT_QUEUE,
            SendTelegramReceipt::class,
            static fn (SendTelegramReceipt $job): bool => $job->orderId === (string) $order->id,
        );
    }

    public function test_receipt_uses_the_persisted_product_icon_snapshot_for_its_caption(): void
    {
        Queue::fake();
        [$order] = $this->paidSingleStructuredReceiptOrder('en', CredentialAccessMethod::None);
        $item = $order->orderItems()->sole();
        $item->update([
            'product_icon' => '🧩',
            'product_custom_emoji_id' => '5368324170671202286',
        ]);
        Product::query()->whereKey($item->product_id)->update(['icon' => '🧾', 'custom_emoji_id' => '5368324170671202287']);
        app(CustomEmojiCapability::class)->markAvailable();
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 834099],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        $photo = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $entities = json_decode((string) $this->telegramRequestField($photo, 'caption_entities'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('🧩 Issue 334 Product', $caption);
        $this->assertContains('5368324170671202286', array_column($entities, 'custom_emoji_id'));
        $this->assertNotContains('5368324170671202287', array_column($entities, 'custom_emoji_id'));
    }

    #[DataProvider('singleStructuredPhotoCases')]
    public function test_single_structured_account_receipt_is_one_protected_localized_photo(
        string $locale,
        CredentialAccessMethod $accessMethod,
    ): void {
        Queue::fake();
        [$order, $sensitiveValues] = $this->paidSingleStructuredReceiptOrder($locale, $accessMethod);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 834001],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptPhotoCaptionEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertCount(1, $effects);
        $this->assertSame('sendPhoto', $effects->sole()->telegram_method);
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_PHOTO_CAPTION,
            $effects->sole()->template_version,
        );
        $this->assertSame('invoice_caption', $effects->sole()->outcome_metadata['unit_role']);
        $this->assertTrue($effects->sole()->outcome_metadata['contains_credentials']);

        $photoRequests = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'));
        $this->assertCount(1, $photoRequests);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $photo = $photoRequests->sole()[0];
        $caption = (string) $this->telegramRequestField($photo, 'caption');
        $this->assertLessThan(TelegramReceiptNotifier::PHOTO_CAPTION_LIMIT, mb_strlen($caption, 'UTF-8'));
        $this->assertStringContainsString($order->invoice_number, $caption);
        $this->assertStringContainsString('Issue 334 Product', $caption);
        $this->assertStringContainsString('Product note once', $caption);
        $this->assertSame(1, substr_count($caption, 'Product note once'));
        $this->assertStringNotContainsString('LHIE STORE', $caption);
        $panel = TelegramOutboundEffect::query()->where('source_id', (string) $order->id)->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)->sole();
        $this->assertSame('v2', $panel->outcome_metadata['receipt_layout_version']);
        $panelText = app(TelegramReceiptActionPanel::class)->render($order, $order->customer->locale, true, withPrivacyGuidance: true)->text();
        $this->assertStringContainsString('LHIE STORE', $panelText);
        $this->assertFalse(filter_var($this->telegramRequestField($photo, 'protect_content'), FILTER_VALIDATE_BOOLEAN));

        $expectedTitles = [
            'id' => '✅ Pembayaran diterima',
            'en' => '✅ Payment received',
            'ms' => '✅ Bayaran diterima',
        ];
        $expectedPaymentLabels = [
            'id' => 'Metode pembayaran: QRIS',
            'en' => 'Payment method: QRIS',
            'ms' => 'Kaedah bayaran: QRIS',
        ];
        $this->assertStringContainsString($expectedTitles[$locale], $caption);
        $this->assertStringContainsString($expectedPaymentLabels[$locale], $caption);
        $this->assertStringContainsString("\n\n".$expectedPaymentLabels[$locale], $caption);
        $this->assertStringContainsString(
            match ($locale) {
                'id' => '🔐 Detail Produk',
                'en' => '🔐 Product Detail',
                'ms' => '🔐 Butiran Produk',
            },
            $caption,
        );

        $email = $sensitiveValues['email'];
        $password = $sensitiveValues['password'];
        $this->assertStringContainsString($email, $caption);
        $this->assertStringContainsString($password, $caption);
        $fields = [$email, $password];
        if ($accessMethod === CredentialAccessMethod::OtpMailLink) {
            $this->assertStringContainsString('otp.issue334.example.test', $caption);
            $this->assertStringNotContainsString($sensitiveValues['access_value'], $caption);
            $markup = json_decode((string) $this->telegramRequestField($photo, 'reply_markup'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame($sensitiveValues['access_value'], data_get($markup, 'inline_keyboard.0.0.url'));
        } elseif ($accessMethod === CredentialAccessMethod::AuthToken) {
            $this->assertStringContainsString($locale === 'en' ? 'access: Auth Token' : 'akses: Auth Token', $caption);
            $this->assertStringContainsString($sensitiveValues['access_value'], $caption);
            $this->assertStringNotContainsString($sensitiveValues['access_value'], json_encode([
                'reply_markup' => $this->telegramRequestField($photo, 'reply_markup'),
                'options' => $this->telegramRequestField($photo, 'caption_entities'),
            ], JSON_THROW_ON_ERROR));
            $fields[] = $sensitiveValues['access_value'];
        } else {
            $this->assertStringNotContainsString('akses:', $caption);
        }

        $entities = json_decode((string) $this->telegramRequestField($photo, 'caption_entities'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(
            [implode(' | ', $fields)],
            array_map(fn (array $entity): string => $this->telegramEntityText($caption, $entity), $entities),
        );
        $this->assertSame('pre', $entities[0]['type']);
        $this->assertSame('copy', $entities[0]['language']);

        $photoBytes = $this->telegramRequestField($photo, 'photo');
        foreach ($sensitiveValues as $value) {
            $this->assertStringNotContainsString($value, $photoBytes);
        }
        $effectJson = json_encode($effects->sole()->getAttributes(), JSON_THROW_ON_ERROR);
        foreach ($sensitiveValues as $value) {
            $this->assertStringNotContainsString($value, $effectJson);
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/pinChatMessage')),
        );
        $this->assertSame(TelegramOutboundEffectState::Sent, $effects->sole()->fresh()->state);
        $this->assertSame('834001', (string) $effects->sole()->fresh()->telegram_message_id);
        $this->assertNull($order->fresh()->telegram_credential_message_id);
        $this->assertNull($order->fresh()->telegram_credential_pinned_at);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/unpinChatMessage')),
        );
        foreach (Queue::pushedJobs() as $jobs) {
            foreach ($jobs as $queued) {
                $this->assertNotSame('telegram-pins', $queued['job']->queue);
            }
        }
    }

    /** @return iterable<string, array{string, CredentialAccessMethod}> */
    public static function singleStructuredPhotoCases(): iterable
    {
        foreach (['id', 'en', 'ms'] as $locale) {
            yield $locale.' none' => [$locale, CredentialAccessMethod::None];
            yield $locale.' otp' => [$locale, CredentialAccessMethod::OtpMailLink];
            yield $locale.' auth token' => [$locale, CredentialAccessMethod::AuthToken];
        }
    }

    public function test_direct_receipt_send_processes_one_combined_photo_without_successor_jobs(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810001],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        Queue::assertNotPushed(SendTelegramReceipt::class);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertSame(
            [TelegramOutboundEffectState::Sent],
            TelegramOutboundEffect::query()
                ->where('effect_group_key', TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->id))
                ->orderBy('sequence')
                ->pluck('state')
                ->all(),
        );
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    #[DataProvider('buyerCredentialPaths')]
    public function test_successful_buyer_pipeline_delivers_receipt_without_credential_persistence(
        string $credentialPath,
    ): void {
        Queue::fake();
        [$order, $sensitiveValues] = $this->paidBuyerReceiptOrder($credentialPath);

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810101],
            ]),
            'https://api.telegram.org/*/sendDocument' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810102],
            ]),
        ]);

        app(TelegramReceiptNotifier::class)->send($order);

        $deliveryRequests = Http::recorded()
            ->filter(static function (array $record): bool {
                $url = $record[0]->url();

                return str_ends_with($url, '/sendPhoto')
                    || str_ends_with($url, '/sendMessage');
            })
            ->values();

        $this->assertCount(1, $deliveryRequests);
        $photoRequest = $deliveryRequests->sole()[0];
        $this->assertStringEndsWith('/sendPhoto', $photoRequest->url());
        $caption = (string) $this->telegramRequestField($photoRequest, 'caption');
        $this->assertStringNotContainsString('LHIE STORE', $caption);
        $panel = TelegramOutboundEffect::query()->where('source_id', (string) $order->id)->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)->sole();
        $this->assertSame('v2', $panel->outcome_metadata['receipt_layout_version']);
        $panelText = app(TelegramReceiptActionPanel::class)->render($order, $order->customer->locale, true, withPrivacyGuidance: true)->text();
        $this->assertStringContainsString('LHIE STORE', $panelText);

        $photoBytes = $this->telegramRequestField($photoRequest, 'photo');
        $this->assertIsString($photoBytes);
        $this->assertSame("\x89PNG\r\n\x1A\n", substr($photoBytes, 0, 8));

        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertCount($credentialPath === 'structured' ? 2 : 1, $effects);
        $this->assertCount(
            $credentialPath === 'structured' ? 1 : 0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')),
        );
        foreach ($sensitiveValues as $sensitiveValue) {
            $this->assertStringNotContainsString(
                $sensitiveValue,
                json_encode($effects->map->getAttributes()->all(), JSON_THROW_ON_ERROR),
            );
            $this->assertStringNotContainsString($sensitiveValue, $photoBytes);
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    /** @return iterable<string, array{string}> */
    public static function buyerCredentialPaths(): iterable
    {
        yield 'structured account' => ['structured'];
        yield 'legacy credential' => ['legacy'];
    }

    public function test_queued_receipt_delivery_dispatches_one_successor_effect(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $invoice = $this->recordLegacyReceiptGroup($order);
        $this->assertNotNull($invoice);
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 810003],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice);

        Queue::assertPushedTimes(SendTelegramReceipt::class, 1);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $detail->id,
        );
    }

    public function test_recovery_waits_for_pending_legacy_invoice_before_dispatching_detail(): void
    {
        Queue::fake();
        $invoice = $this->recordLegacyReceiptGroup($this->paidOrderWithoutCredentials());
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);

        $this->assertFalse($ledger->readyToDispatchQuery(now(), $detail->effect_type)->whereKey($detail->id)->exists());

        $invoice->forceFill(['state' => TelegramOutboundEffectState::Unknown])->save();

        $this->assertTrue($ledger->readyToDispatchQuery(now(), $detail->effect_type)->whereKey($detail->id)->exists());
    }

    public function test_invoice_metadata_and_buyer_text_keep_provider_total_when_present(): void
    {
        $order = $this->paidOrderWithAmountMismatch('IDR', 12_345, 98_765);
        $order->payments()->sole()->forceFill(['provider_total_payment' => 13_579])->save();
        $order->refresh()->load(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);

        $invoice = app(PaidInvoiceRenderer::class)->render(
            app(PaidInvoiceOrderPresentationAdapter::class)->present($order),
        );
        $detail = app(TelegramReceiptNotifier::class)->renderProductDetail($order)['units'][0];

        $this->assertSame(13_579, $invoice->metadata['total_paid']);
        $this->assertStringContainsString('Jumlah dibayar: Rp 13.579', $detail->text());
    }

    private function paidOrderWithAmountMismatch(string $currency, int $orderTotal, int $paymentAmount): Order
    {
        $order = $this->paidOrderWithoutCredentials();
        $order->forceFill([
            'currency' => $currency,
            'total_amount' => $orderTotal,
        ])->save();
        $order->payments()->sole()->forceFill([
            'amount' => $paymentAmount,
            'currency' => $currency,
            'provider_total_payment' => null,
        ])->save();

        return $order->fresh(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
    }

    public function test_invoice_failure_dispatches_product_detail_and_completion_ignores_photo_failure(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $invoice = $this->recordLegacyReceiptGroup($order);
        $this->assertNotNull($invoice);
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => false,
                'description' => 'invoice photo rejected',
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 800001],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice);

        $this->assertSame(TelegramOutboundEffectState::Failed, $invoice->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $detail->fresh()->state);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $detail->id,
        );

        app(ReceiptDeliveryPipeline::class)->deliver($detail, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $detail->fresh()->state);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_ambiguous_invoice_is_unknown_but_product_detail_still_delivers(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $invoice = $this->recordLegacyReceiptGroup($order);
        $this->assertNotNull($invoice);
        $detail = TelegramOutboundEffect::query()
            ->where('effect_group_key', $invoice->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function (): never {
                throw new ConnectionException('invoice photo timed out after Telegram accepted it');
            },
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 800002],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($invoice);

        $this->assertSame(TelegramOutboundEffectState::Unknown, $invoice->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $detail->fresh()->state);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $detail->id,
        );

        app(ReceiptDeliveryPipeline::class)->deliver($detail, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $detail->fresh()->state);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_preexisting_text_only_receipt_template_remains_dispatchable(): void
    {
        $order = $this->paidOrderWithoutCredentials();
        $item = $order->orderItems()->sole();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => 'old-receipt@example.test|Old-password',
        ]);
        $groupKey = TelegramOutboundEffectService::legacyReceiptEffectGroupKey($order->id);
        $effect = TelegramOutboundEffect::create([
            'effect_key' => $groupKey.':unit:1',
            'effect_group_key' => $groupKey,
            'sequence' => 1,
            'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
            'source_type' => Order::class,
            'source_id' => (string) $order->id,
            'target_type' => 'customer',
            'telegram_chat_id' => (string) $order->customer->telegram_id,
            'queue' => TelegramOutboundEffectService::RECEIPT_QUEUE,
            'telegram_method' => 'sendMessage',
            'locale' => 'id',
            'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_CREDENTIALS,
            'state' => TelegramOutboundEffectState::Pending,
            'attempts' => 0,
            'next_attempt_at' => now(),
            'outcome_metadata' => [
                'delivery_context' => 'receipt',
                'unit_role' => 'product_detail',
                'receipt_chunk_index' => 0,
                'receipt_chunk_count' => 1,
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 800003],
            ]),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $effect->fresh()->state);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))->sole()[0];
        $this->assertStringContainsString('old-receipt@example.test', (string) $request['text']);
        $this->assertStringContainsString('Old-password', (string) $request['text']);
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
    }

    public function test_partially_sent_legacy_invoice_keeps_token_in_its_pending_successor(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $item = $order->orderItems()->sole();
        $token = str_repeat('T', 3400);
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'old-token@example.test', 'Old-password', CredentialAccessMethod::AuthToken,
                $token, str_repeat('Tutorial ', 100),
            )->toArray(),
        ]);
        $first = $this->recordLegacyReceiptGroup($order);
        $effects = TelegramOutboundEffect::where('effect_group_key', $first->effect_group_key)->orderBy('sequence')->get();
        foreach ($effects->where('sequence', '<', 4) as $effect) {
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sent,
                'sent_at' => now()->subMinute(),
                'telegram_message_id' => (string) (810000 + $effect->sequence),
            ])->save();
        }
        $this->fakeTelegramHttp();
        foreach ($effects->where('sequence', '>=', 4) as $effect) {
            app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);
        }
        $sentText = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'))
            ->map(fn ($pair): string => (string) $pair[0]['text'])->implode("\n");
        $this->assertSame(1, substr_count($sentText, $token));
        $this->assertStringNotContainsString('old-token@example.test', $sentText);
        $this->assertStringNotContainsString(str_repeat('Tutorial ', 100), $sentText);
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_pending_photo_receipt_without_copy_marker_keeps_its_original_field_layout(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $item = $order->orderItems()->sole();
        ProductStockItem::create([
            'product_id' => $item->product_id,
            'order_item_id' => $item->id,
            'secret' => 'old-photo@example.test|Old-password',
        ]);
        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());
        $metadata = $effect->outcome_metadata;
        unset($metadata['credential_layout_version']);
        $effect->forceFill(['outcome_metadata' => $metadata])->save();
        $this->fakeTelegramHttp();
        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $request = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto'))->sole()[0];
        $caption = (string) $this->telegramRequestField($request, 'caption');
        $entities = json_decode((string) $this->telegramRequestField($request, 'caption_entities'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['old-photo@example.test', 'Old-password'], array_map(
            fn (array $entity): string => $this->telegramEntityText($caption, $entity),
            $entities,
        ));
        $this->assertTrue(filter_var($this->telegramRequestField($request, 'protect_content'), FILTER_VALIDATE_BOOLEAN));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_legacy_receipt_groups_are_not_silently_upgraded_with_action_panels(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $legacy = $this->recordLegacyReceiptGroup($order);
        $sentAt = now()->subMinute();

        TelegramOutboundEffect::query()
            ->where('effect_group_key', $legacy->effect_group_key)
            ->get()
            ->each(function (TelegramOutboundEffect $effect) use ($sentAt): void {
                $effect->forceFill([
                    'state' => TelegramOutboundEffectState::Sent,
                    'telegram_message_id' => (string) (891000 + (int) $effect->sequence),
                    'sent_at' => $sentAt,
                ])->save();
            });
        $order->forceFill(['telegram_receipt_sent_at' => $sentAt])->save();
        $legacySnapshot = TelegramOutboundEffect::query()
            ->where('effect_group_key', $legacy->effect_group_key)
            ->orderBy('sequence')
            ->get()
            ->map(fn (TelegramOutboundEffect $effect): array => $effect->getAttributes())
            ->all();

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertSame(
            0,
            TelegramOutboundEffect::query()
                ->where('effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS)
                ->count(),
        );
        Queue::assertNotPushed(SendTelegramReceiptActionPanel::class);
        $this->assertSame($legacySnapshot, TelegramOutboundEffect::query()
            ->where('effect_group_key', $legacy->effect_group_key)
            ->orderBy('sequence')
            ->get()
            ->map(fn (TelegramOutboundEffect $effect): array => $effect->getAttributes())
            ->all());
    }

    public function test_recovery_materializes_a_paid_receipt_without_a_legacy_claim(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();

        $this->artisan('telegram:recover-outbound')
            ->assertSuccessful();

        Queue::assertPushedOn(
            TelegramOutboundEffectService::RECEIPT_QUEUE,
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->orderId === (string) $order->id
                && $job->effectId !== null,
        );
    }

    public function test_recovery_repairs_a_complete_sent_receipt_without_resending_it(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($first);

        $sentAt = now()->subMinute();
        TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->get()
            ->each(function (TelegramOutboundEffect $effect) use ($sentAt): void {
                $effect->forceFill([
                    'state' => TelegramOutboundEffectState::Sent,
                    'telegram_message_id' => (string) (880000 + (int) $effect->sequence),
                    'sent_at' => $sentAt,
                ])->save();
            });
        $order->forceFill([
            'buyer_order_detail_message_chat_id' => (string) $order->customer->telegram_id,
            'buyer_order_detail_message_id' => 880099,
        ])->save();

        $this->assertNull($order->fresh()->telegram_receipt_sent_at);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        Queue::assertPushedOn(
            'notifications',
            DeleteBuyerCheckoutMessages::class,
            fn (DeleteBuyerCheckoutMessages $job): bool => $job->orderId === (string) $order->id,
        );
        Queue::assertNotPushed(SendTelegramReceipt::class);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        Queue::assertPushedTimes(DeleteBuyerCheckoutMessages::class, 1);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_recovery_still_dispatches_cleanup_for_a_klikqris_checkout_instruction(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $order->forceFill([
            'telegram_receipt_sent_at' => now(),
            'manual_checkout_customer_notification_chat_id' => (string) $order->customer->telegram_id,
            'manual_checkout_customer_notification_message_id' => 889904,
        ])->save();
        Payment::query()
            ->where('order_id', $order->id)
            ->update(['provider' => PaymentProvider::KLIKQRIS]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        Queue::assertPushedOn(
            'notifications',
            DeleteBuyerCheckoutMessages::class,
            fn (DeleteBuyerCheckoutMessages $job): bool => $job->orderId === (string) $order->id,
        );
    }

    public function test_recovery_repairs_a_complete_legacy_receipt_with_unresolved_invoice(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $first = $this->recordLegacyReceiptGroup($order);

        $sentAt = now()->subMinute();
        TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->update([
                'state' => TelegramOutboundEffectState::Sent->value,
                'telegram_message_id' => '881000',
                'sent_at' => $sentAt,
            ]);
        DB::table('telegram_outbound_effects')->where('id', $first->id)->update([
            'state' => TelegramOutboundEffectState::Unknown->value,
            'telegram_message_id' => null,
            'sent_at' => null,
            'unknown_at' => $sentAt,
        ]);
        DB::table('orders')->where('id', $order->id)->update([
            'buyer_order_detail_message_chat_id' => (string) $order->customer->telegram_id,
            'buyer_order_detail_message_id' => 881099,
        ]);

        $this->artisan('telegram:recover-outbound', ['--chunk' => 1])
            ->assertSuccessful();

        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        Queue::assertPushedOn(
            'notifications',
            DeleteBuyerCheckoutMessages::class,
            fn (DeleteBuyerCheckoutMessages $job): bool => $job->orderId === (string) $order->id,
        );
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_ordered_receipt_stops_after_an_ambiguous_credential_unit_without_resending_previous_units(): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $first = $this->recordLegacyReceiptGroup($order);
        $this->assertNotNull($first);
        $summary = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();
        $credential = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 3)
            ->firstOrFail();
        $later = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 4)
            ->firstOrFail();

        $photoCount = 0;
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$photoCount): mixed {
                $photoCount++;

                return Http::response(['ok' => true, 'result' => ['message_id' => 700 + $photoCount]]);
            },
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendCount): mixed {
                $sendCount++;
                if ($sendCount === 2) {
                    throw new ConnectionException('timeout after Telegram accepted the credential chunk');
                }

                return Http::response(['ok' => true, 'result' => ['message_id' => 710 + $sendCount]]);
            },
        ]);

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $pipeline->deliver($summary, dispatchNext: false);
        $pipeline->deliver($credential, dispatchNext: false);
        $pipeline->deliver($later, dispatchNext: false);

        $this->assertSame(1, $photoCount);
        $this->assertSame(2, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Sent, $summary->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $credential->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertStringNotContainsString(
            'buyer0@example.test',
            json_encode($credential->fresh()->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_marking_the_final_receipt_chunk_sent_completes_the_legacy_projection(): void
    {
        $order = $this->multiChunkPaidOrder();
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $first = $this->recordLegacyReceiptGroup($order);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 900000],
            ]),
            'https://api.telegram.org/*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 900001],
            ]),
        ]);
        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $current = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', '>', $first->sequence)
            ->orderBy('sequence')
            ->first();
        $this->assertNotNull($current);

        while (true) {
            $current->forceFill([
                'state' => TelegramOutboundEffectState::Sent,
                'sent_at' => now(),
            ])->save();

            $next = TelegramOutboundEffect::query()
                ->where('effect_group_key', $current->effect_group_key)
                ->where('sequence', '>', $current->sequence)
                ->whereIn('state', [TelegramOutboundEffectState::Pending, TelegramOutboundEffectState::Failed])
                ->orderBy('sequence')
                ->first();
            if ($next === null) {
                break;
            }

            $current = $next;
        }

        $current->forceFill([
            'state' => TelegramOutboundEffectState::Unknown,
            'unknown_at' => now(),
        ])->save();

        $this->assertTrue(app(TelegramOutboundEffectReviewService::class)->markSent($current, '900002'));
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertSame(TelegramOutboundEffectState::Sent, $current->fresh()->state);
    }

    #[DataProvider('unresolvedFirstPhotoStates')]
    public function test_new_photo_caption_receipt_blocks_continuations_after_unresolved_first_unit(
        TelegramOutboundEffectState $state,
    ): void {
        $order = $this->multiChunkPaidOrder();
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($first);
        $second = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        $first->forceFill([
            'state' => $state,
            'failed_at' => $state === TelegramOutboundEffectState::Failed ? now() : null,
            'unknown_at' => $state === TelegramOutboundEffectState::Unknown ? now() : null,
            'manual_review_at' => $state === TelegramOutboundEffectState::ManualReview ? now() : null,
            'next_attempt_at' => null,
        ])->save();

        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);

        $this->assertFalse($ledger->isDispatchable($second->fresh()));
        $this->assertFalse($ledger->readyToDispatchQuery()->whereKey($second->getKey())->exists());
    }

    #[DataProvider('rejectedTelegramResponses')]
    public function test_rejected_telegram_responses_never_mark_a_receipt_chunk_sent(array $response): void
    {
        $order = $this->multiChunkPaidOrder();
        $effect = $this->recordLegacyReceiptGroup($order);
        $this->assertNotNull($effect);
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response($response),
            'https://api.telegram.org/*/sendMessage' => Http::response($response),
        ]);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $freshEffect = $effect->fresh();
        $this->assertSame(TelegramOutboundEffectState::Failed, $freshEffect->state);
        $this->assertNull($freshEffect->telegram_message_id);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
    }

    #[DataProvider('acceptedTelegramResponsesWithoutAProvenMessageId')]
    public function test_accepted_receipt_chunk_without_a_proven_message_id_is_ambiguous_and_never_resent(array $response): void
    {
        $order = $this->multiChunkPaidOrder();
        $first = $this->recordLegacyReceiptGroup($order);
        $summary = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();
        $credential = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 3)
            ->firstOrFail();
        $later = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 4)
            ->firstOrFail();

        $photoCount = 0;
        $sendCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => function () use (&$photoCount): mixed {
                $photoCount++;

                return Http::response(['ok' => true, 'result' => ['message_id' => 700 + $photoCount]]);
            },
            'https://api.telegram.org/*/sendMessage' => function () use (&$sendCount, $response): mixed {
                $sendCount++;

                return $sendCount < 2
                    ? Http::response(['ok' => true, 'result' => ['message_id' => 710 + $sendCount]])
                    : Http::response($response);
            },
        ]);

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $pipeline->deliver($summary, dispatchNext: false);
        $pipeline->deliver($credential, dispatchNext: false);

        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $ambiguous = $credential->fresh();

        $this->assertSame(1, $photoCount);
        $this->assertSame(2, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $ambiguous->state);
        $this->assertSame('telegram_transport_ambiguous', $ambiguous->last_error_code);
        $this->assertNull($ambiguous->next_attempt_at);
        $this->assertNull($ambiguous->telegram_message_id);
        $this->assertFalse($ledger->isDispatchable($ambiguous));
        $this->assertFalse($ledger->readyToDispatchQuery()->whereKey($ambiguous->getKey())->exists());
        $this->assertSame(TelegramOutboundEffectState::Pending, $later->fresh()->state);

        $this->travel(10)->minutes();

        $this->assertFalse($ledger->isDispatchable($credential->fresh()));

        $this->artisan('telegram:recover-outbound', ['--chunk' => 25])
            ->assertSuccessful();

        $this->assertSame(1, $photoCount);
        $this->assertSame(2, $sendCount);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $credential->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Pending, $later->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertStringNotContainsString(
            'buyer0@example.test',
            json_encode($credential->fresh()->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    public function test_overflow_receipt_uses_one_photo_and_one_complete_document(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $plan = app(TelegramReceiptNotifier::class)->receiptPlan($order);
        $this->assertTrue($plan->usesDocument());
        $this->assertSame(2, $plan->photoUnitCount());
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 820002]]),
        ]);
        app(TelegramReceiptNotifier::class)->send($order);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')));
        $document = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument'))->sole()[0];
        $payload = app(TelegramPaidOrderTxtExporter::class)->export($order, $order->customer);
        $this->assertTrue($document->hasFile('document', $payload->documentContents(), $payload->filename()));
        for ($index = 0; $index < 60; $index++) {
            $this->assertSame(1, substr_count($payload->documentContents(), 'buyer'.$index.'@example.test'));
        }
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
        $effects = TelegramOutboundEffect::query()->where('effect_group_key', TelegramOutboundEffectService::receiptEffectGroupKey((string) $order->id))->get();
        $this->assertCount(2, $effects);
        $this->assertTrue($effects->every(fn ($effect): bool => $effect->state === TelegramOutboundEffectState::Sent));
    }

    public function test_document_continuation_rechecks_credentials_after_the_summary_attempt(): void
    {
        Queue::fake();
        $order = $this->multiProductStructuredReceiptOrder();
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $next = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 2)
            ->sole();
        $this->fakeTelegramHttp();
        app(ReceiptDeliveryPipeline::class)->deliver($first, dispatchNext: false);
        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);

        $order->orderItems()->firstOrFail()->stockItems()->firstOrFail()->update(['retired_at' => now()]);
        app(ReceiptDeliveryPipeline::class)->deliver($next, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Failed, $next->fresh()->state);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertCount(0, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendDocument')));
    }

    public function test_admin_outcome_uses_current_allocations_without_changing_the_unresolved_deadline(): void
    {
        Queue::fake();
        $this->freezeTime();
        $order = $this->multiProductStructuredReceiptOrder();
        $order->load('orderItems.stockItems');
        $reader = app(AdminPaidOrderOutcome::class);
        $this->assertFalse($reader->read($order)['ready']);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 820003]]),
        ]);
        app(TelegramReceiptNotifier::class)->send($order);
        $this->assertTrue($reader->read($order)['resolved']);

        $item = $order->orderItems->first();
        $item->stockItems->first()->update(['order_item_id' => null]);
        $this->travel(119)->seconds();
        $outcome = $reader->read($order);
        $this->assertFalse($outcome['resolved']);
        $this->assertFalse($outcome['ready']);
        $this->assertStringContainsString('1/2 unit terkirim', $outcome['lines'][0]);
        $this->travel(1)->seconds();
        $outcome = $reader->read($order);
        $this->assertFalse($outcome['resolved']);
        $this->assertTrue($outcome['ready']);
    }

    public function test_queued_overflow_receipt_dispatches_its_next_strict_chunk_after_photo_success(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($first);
        $next = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->where('sequence', 2)
            ->firstOrFail();

        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 825001],
            ]),
        ]);
        app(ReceiptDeliveryPipeline::class)->deliver($first);

        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        Queue::assertPushed(
            SendTelegramReceipt::class,
            fn (SendTelegramReceipt $job): bool => $job->effectId === (string) $next->id,
        );
    }

    public function test_explicit_chat_multi_product_receipt_keeps_numbering_guidance_and_buttons_scoped(): void
    {
        Queue::fake();
        $order = $this->multiProductStructuredReceiptOrder();
        $plan = app(TelegramReceiptNotifier::class)->receiptPlan($order, documentDelivery: false);
        $this->assertSame(1, $plan->photoUnitCount());

        $unit = $plan->photoUnit(1);
        $caption = $unit->text();
        $this->assertStringContainsString('Product Alpha', $caption);
        $this->assertStringContainsString('Product Beta', $caption);
        $this->assertSame(2, substr_count($caption, 'Account #1'));
        $this->assertSame(1, substr_count($caption, 'Account #2'));
        $this->assertSame(1, substr_count($caption, 'Alpha delivery note'));
        $this->assertSame(1, substr_count($caption, 'Beta delivery note'));
        $this->assertSame(1, substr_count($caption, 'Alpha Guide'));
        $this->assertSame(1, substr_count($caption, 'Beta Guide'));
        $this->assertStringNotContainsString('https://guide.alpha.example.test', $caption);
        $this->assertStringNotContainsString('https://guide.beta.example.test', $caption);

        $markup = $unit->replyMarkup();
        $buttonUrls = collect(data_get($markup, 'inline_keyboard', []))
            ->map(fn (array $row): ?string => $row[0]['url'] ?? null)
            ->filter()
            ->all();
        $this->assertContains('https://guide.alpha.example.test', $buttonUrls);
        $this->assertContains('https://guide.beta.example.test', $buttonUrls);
    }

    public function test_long_product_snapshot_header_spills_into_ordered_continuation(): void
    {
        Queue::fake();
        $order = $this->paidOrderWithoutCredentials();
        $longProductName = str_repeat('Long Product Snapshot ', 80);
        $order->orderItems()->sole()->forceFill([
            'product_name' => $longProductName,
        ])->save();
        $plan = app(TelegramReceiptNotifier::class)->receiptPlan($order->fresh());
        $this->assertGreaterThan(1, $plan->photoUnitCount());

        $this->fakeTelegramHttp();
        app(TelegramReceiptNotifier::class)->send($order->fresh());

        $requests = Http::recorded()
            ->filter(static fn (array $record): bool => str_ends_with($record[0]->url(), '/sendPhoto')
                || str_ends_with($record[0]->url(), '/sendMessage'))
            ->values();
        $this->assertSame(1, $requests->filter(
            static fn (array $record): bool => str_ends_with($record[0]->url(), '/sendPhoto'),
        )->count());
        $this->assertStringNotContainsString(
            $longProductName,
            (string) $this->telegramRequestField($requests->first()[0], 'caption'),
        );
        $this->assertStringContainsString(
            $longProductName,
            $requests->slice(1)
                ->map(fn (array $record): string => (string) $this->telegramRequestField($record[0], 'text'))
                ->implode("\n"),
        );
    }

    public function test_explicit_chat_identical_duplicate_product_items_merge_guidance_and_restart_only_per_product(): void
    {
        Queue::fake();
        $order = $this->multiProductStructuredReceiptOrder();
        $product = Product::query()->where('name', 'Product Alpha')->sole();
        $duplicateItem = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => 10_000,
            'subtotal' => 10_000,
        ]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $duplicateItem->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'alpha-three@example.test',
                'Alpha-three-password',
                CredentialAccessMethod::None,
            )->toArray(),
        ]);

        $plan = app(TelegramReceiptNotifier::class)->receiptPlan($order->fresh(), documentDelivery: false);
        $this->assertSame(2, $plan->productCount());

        $caption = implode("\n", array_map(
            static fn (TelegramMessageUnit $unit): string => $unit->text(),
            $plan->photoUnits(),
        ));

        $this->assertSame(2, substr_count($caption, 'Account #1'));
        $this->assertSame(1, substr_count($caption, 'Account #2'));
        $this->assertSame(1, substr_count($caption, 'Account #3'));
        $this->assertSame(1, substr_count($caption, 'Alpha delivery note'));
        $this->assertSame(1, substr_count($caption, 'Alpha Guide'));
    }

    public function test_new_receipt_stops_after_ambiguous_continuation_without_resending_prior_chunks(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $first = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($first);
        $effects = TelegramOutboundEffect::query()
            ->where('effect_group_key', $first->effect_group_key)
            ->orderBy('sequence')
            ->get();
        $this->assertCount(2, $effects);
        $second = $effects[1];

        $sendMessageCount = 0;
        Http::preventStrayRequests();
        Http::fake([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 830001],
            ]),
            'https://api.telegram.org/*/sendDocument' => function () use (&$sendMessageCount): never {
                $sendMessageCount++;
                throw new ConnectionException('continuation timed out after Telegram accepted it');
            },
        ]);

        $pipeline = app(ReceiptDeliveryPipeline::class);
        $pipeline->deliver($first, dispatchNext: false);
        $pipeline->deliver($second, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Sent, $first->fresh()->state);
        $this->assertSame(TelegramOutboundEffectState::Unknown, $second->fresh()->state);
        $this->assertSame(1, $sendMessageCount);
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
    }

    public function test_pre_send_invoice_image_failure_uses_explicit_text_fallback(): void
    {
        Queue::fake();
        [$order] = $this->paidSingleStructuredReceiptOrder('en', CredentialAccessMethod::None);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class, [
            app(TelegramBotClient::class),
            app(TelegramLocale::class),
            app(CredentialDeliveryFormatter::class),
            app(CredentialDeliveryPresentation::class),
            app(TelegramCredentialDeliveryRenderer::class),
            app(TelegramMessageChunker::class),
            app(TelegramSupportContact::class),
            app(PaidInvoiceRenderer::class),
            app(PaidInvoiceOrderPresentationAdapter::class),
            app(TelegramOutboundEffectLedgerProtocol::class),
            app(TelegramUiIcons::class),
            app(TelegramProductNameRenderer::class),
        ])->makePartial();
        $notifier->shouldReceive('renderInvoiceUnit')
            ->andThrow(new TelegramOutboundPreSendException('deterministic invoice image failure'));
        app()->instance(TelegramReceiptNotifier::class, $notifier);

        $this->fakeTelegramHttp();
        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);

        $this->assertNotNull($effect);
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK,
            $effect->template_version,
        );
        $this->assertSame('sendMessage', $effect->telegram_method);
        $this->assertSame('text_fallback', $effect->outcome_metadata['render_mode']);
        $this->assertFalse($effect->outcome_metadata['has_photo']);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
        $messages = Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage'));
        $this->assertCount(1, $messages);
        $message = $messages->sole()[0];
        $this->assertStringContainsString('Payment received', (string) $this->telegramRequestField($message, 'text'));
    }

    public function test_runtime_pre_send_image_failure_materializes_and_delivers_text_fallback(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $this->fakeTelegramHttp(['https://api.telegram.org/*/sendDocument' => Http::response(['ok' => true, 'result' => ['message_id' => 830099]])]);
        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($effect);

        $notifier = Mockery::mock(TelegramReceiptNotifier::class, [
            app(TelegramBotClient::class),
            app(TelegramLocale::class),
            app(CredentialDeliveryFormatter::class),
            app(CredentialDeliveryPresentation::class),
            app(TelegramCredentialDeliveryRenderer::class),
            app(TelegramMessageChunker::class),
            app(TelegramSupportContact::class),
            app(PaidInvoiceRenderer::class),
            app(PaidInvoiceOrderPresentationAdapter::class),
            app(TelegramOutboundEffectLedgerProtocol::class),
            app(TelegramUiIcons::class),
            app(TelegramProductNameRenderer::class),
        ])->makePartial();
        $notifier->shouldReceive('renderInvoiceUnit')
            ->andThrow(new TelegramOutboundPreSendException('Telegram paid invoice rendering failed before send.'));
        app()->instance(TelegramReceiptNotifier::class, $notifier);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertSame('receipt_render_changed', $effect->fresh()->last_error_code);
        $fallback = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptTextFallbackEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertGreaterThan(1, $fallback->count());
        $this->assertTrue($fallback->every(
            fn (TelegramOutboundEffect $candidate): bool => $candidate->state === TelegramOutboundEffectState::Sent,
        ));
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK,
            $fallback->first()->template_version,
        );
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
        $this->assertCount(
            $fallback->count() - 1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    #[DataProvider('definitePhotoRejectionStatuses')]
    public function test_definite_send_photo_rejection_falls_back_to_ordered_text_receipt(int $httpStatus): void
    {
        Queue::fake();
        [$order] = $this->paidSingleStructuredReceiptOrder('en', CredentialAccessMethod::None);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => Http::response([
                'ok' => false,
                'error_code' => $httpStatus,
                'description' => 'Bad Request: photo rejected',
            ], $httpStatus),
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($effect);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertSame('telegram_http_'.$httpStatus, $effect->fresh()->last_error_code);

        $fallback = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptTextFallbackEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertNotEmpty($fallback);
        $this->assertTrue($fallback->every(
            fn (TelegramOutboundEffect $candidate): bool => $candidate->state === TelegramOutboundEffectState::Sent,
        ));
        $this->assertSame(
            TelegramOutboundEffectService::TEMPLATE_RECEIPT_TEXT_FALLBACK,
            $fallback->first()->template_version,
        );
        $this->assertCount(
            1,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')),
        );
        $this->assertCount(
            $fallback->count(),
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertNotNull($order->fresh()->telegram_receipt_sent_at);
    }

    #[DataProvider('ambiguousPhotoFailures')]
    public function test_ambiguous_send_photo_failure_keeps_receipt_unknown_without_fallback(int|string $failure): void
    {
        Queue::fake();
        $order = $this->multiProductStructuredReceiptOrder();
        $photoAttempts = 0;
        $photoFailure = function () use ($failure, &$photoAttempts) {
            $photoAttempts++;
            if ($failure === 'transport') {
                throw new ConnectionException('connection closed after Telegram may have accepted the photo');
            }

            return Http::response([
                'ok' => false,
                'error_code' => $failure,
                'description' => 'Temporary Telegram failure',
            ], $failure);
        };
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => $photoFailure,
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($effect);

        app(ReceiptDeliveryPipeline::class)->deliver($effect);

        $this->assertSame(TelegramOutboundEffectState::Unknown, $effect->fresh()->state);
        $this->assertSame('telegram_transport_ambiguous', $effect->fresh()->last_error_code);
        $this->assertSame(1, $photoAttempts);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')),
        );
        $this->assertSame(
            0,
            TelegramOutboundEffect::query()
                ->where('effect_group_key', TelegramOutboundEffectService::receiptTextFallbackEffectGroupKey((string) $order->id))
                ->count(),
        );
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        Queue::assertNotPushed(SendTelegramReceipt::class);
    }

    public function test_text_fallback_http_400_keeps_receipt_incomplete_and_blocks_later_chunks(): void
    {
        Queue::fake();
        $order = $this->multiChunkPaidOrder();
        $rejected = Http::response([
            'ok' => false,
            'description' => 'Bad Request: invalid receipt payload',
        ], 400);
        $this->fakeTelegramHttp([
            'https://api.telegram.org/*/sendPhoto' => $rejected,
            'https://api.telegram.org/*/sendMessage' => $rejected,
        ]);

        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order);
        $this->assertNotNull($effect);

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);

        $fallback = TelegramOutboundEffect::query()
            ->where('effect_group_key', TelegramOutboundEffectService::receiptTextFallbackEffectGroupKey((string) $order->id))
            ->orderBy('sequence')
            ->get();
        $this->assertNotEmpty($fallback);
        $this->assertGreaterThan(1, $fallback->count());
        $this->assertSame(TelegramOutboundEffectState::Failed, $fallback->first()->state);
        $this->assertSame('telegram_http_400', $fallback->first()->last_error_code);
        $this->assertTrue($fallback->skip(1)->every(
            fn (TelegramOutboundEffect $candidate): bool => $candidate->state === TelegramOutboundEffectState::Pending,
        ));
        $this->assertNull($order->fresh()->telegram_receipt_sent_at);
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')));
        $this->assertCount(1, Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendMessage')));
    }

    public function test_malformed_credential_creates_safe_failed_review_without_partial_delivery(): void
    {
        Queue::fake();
        [$order] = $this->paidSingleStructuredReceiptOrder('en', CredentialAccessMethod::None);
        $stockItem = ProductStockItem::query()->where('order_item_id', $order->orderItems()->sole()->id)->sole();
        DB::table('product_stock_items')
            ->where('id', $stockItem->id)
            ->update(['credential_payload' => 'corrupt']);

        $this->fakeTelegramHttp();
        $effect = app(TelegramOutboundEffectService::class)->recordReceipt($order->fresh());

        $this->assertNotNull($effect);
        $this->assertSame(TelegramOutboundEffectState::Failed, $effect->fresh()->state);
        $this->assertSame('receipt_render_changed', $effect->fresh()->last_error_code);
        $this->assertSame('Telegram receipt rendering requires manual review.', $effect->fresh()->last_error);
        $this->assertStringNotContainsString(
            'malformed@example.test',
            json_encode($effect->fresh()->getAttributes(), JSON_THROW_ON_ERROR),
        );

        app(ReceiptDeliveryPipeline::class)->deliver($effect, dispatchNext: false);
        $this->assertCount(
            0,
            Http::recorded(fn ($request): bool => str_ends_with($request->url(), '/sendPhoto')
                || str_ends_with($request->url(), '/sendMessage')),
        );
    }

    /** @return array{0: Order, 1: array{email: string, password: string, access_value?: string, otp_domain?: string}} */
    private function paidSingleStructuredReceiptOrder(string $locale, CredentialAccessMethod $accessMethod): array
    {
        $localeIndex = array_search($locale, ['id', 'en', 'ms'], true);
        $methodIndex = array_search($accessMethod, [
            CredentialAccessMethod::None,
            CredentialAccessMethod::OtpMailLink,
            CredentialAccessMethod::AuthToken,
        ], true);
        $customer = Customer::factory()->create([
            'telegram_id' => 334000 + ((int) $localeIndex * 10) + (int) $methodIndex,
            'locale' => $locale,
        ]);
        $product = Product::factory()->create(['name' => 'Issue 334 Product']);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-334-'.$locale.'-'.$accessMethod->value,
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 12_500,
            'currency' => 'IDR',
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'delivery_note' => 'Product note once',
            'delivery_note_snapshotted' => true,
            'quantity' => 1,
            'unit_price' => 12_500,
            'subtotal' => 12_500,
        ]);
        Payment::factory()->for($order)->create([
            'method' => 'QR_MANUAL',
            'amount' => 12_500,
            'provider_total_payment' => 12_500,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        $email = 'issue334-'.$locale.'-'.$accessMethod->value.'@example.test';
        $password = 'Issue334-'.$locale.'-password';
        $accessValue = match ($accessMethod) {
            CredentialAccessMethod::None => null,
            CredentialAccessMethod::OtpMailLink => 'https://otp.issue334.example.test/mail?opaque=issue334-'.$locale,
            CredentialAccessMethod::AuthToken => 'Issue334-'.$locale.'-auth-token',
        };
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                $email,
                $password,
                $accessMethod,
                $accessValue,
            )->toArray(),
        ]);

        $sensitiveValues = [
            'email' => $email,
            'password' => $password,
        ];
        if ($accessValue !== null) {
            $sensitiveValues['access_value'] = $accessValue;
        }
        if ($accessMethod === CredentialAccessMethod::OtpMailLink) {
            $sensitiveValues['otp_domain'] = 'otp.issue334.example.test';
        }

        return [$order->fresh(), $sensitiveValues];
    }

    private function multiProductStructuredReceiptOrder(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 335001, 'locale' => 'en']);
        $productA = Product::factory()->create([
            'name' => 'Product Alpha',
            'delivery_note' => 'Alpha delivery note',
            'usage_guide_title' => 'Alpha Guide',
            'usage_guide_url' => 'https://guide.alpha.example.test',
        ]);
        $productB = Product::factory()->create([
            'name' => 'Product Beta',
            'delivery_note' => 'Beta delivery note',
            'usage_guide_title' => 'Beta Guide',
            'usage_guide_url' => 'https://guide.beta.example.test',
        ]);
        $order = Order::factory()->for($customer)->create([
            'invoice_number' => 'INV-335-MULTI',
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => 30_000,
        ]);
        $itemA = OrderItem::factory()->for($order)->create([
            'product_id' => $productA->id,
            'product_name' => $productA->name,
            'quantity' => 2,
            'unit_price' => 10_000,
            'subtotal' => 20_000,
        ]);
        $itemB = OrderItem::factory()->for($order)->create([
            'product_id' => $productB->id,
            'product_name' => $productB->name,
            'quantity' => 1,
            'unit_price' => 10_000,
            'subtotal' => 10_000,
        ]);
        Payment::factory()->for($order)->create([
            'amount' => 30_000,
            'provider_total_payment' => 30_000,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        foreach ([
            [$itemA, $productA, 'alpha-one@example.test', 'Alpha-one-password'],
            [$itemA, $productA, 'alpha-two@example.test', 'Alpha-two-password'],
        ] as [$item, $product, $email, $password]) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => StructuredCredentialPayload::fromValues(
                    $email,
                    $password,
                    CredentialAccessMethod::None,
                )->toArray(),
            ]);
        }
        ProductStockItem::create([
            'product_id' => $productB->id,
            'order_item_id' => $itemB->id,
            'credential_format' => CredentialFormat::StructuredAccount,
            'credential_payload' => StructuredCredentialPayload::fromValues(
                'beta@example.test',
                'Beta-password',
                CredentialAccessMethod::AuthToken,
                'beta-auth-token',
            )->toArray(),
        ]);

        return $order->fresh();
    }

    private function recordLegacyReceiptGroup(Order $order): TelegramOutboundEffect
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product', 'payments']);
        $detailUnits = app(TelegramReceiptNotifier::class)->renderProductDetail($order, copyableCredentials: false)['units'];
        $groupKey = TelegramOutboundEffectService::legacyReceiptEffectGroupKey((string) $order->id);
        $unitCount = 1 + count($detailUnits);
        $first = null;

        for ($sequence = 1; $sequence <= $unitCount; $sequence++) {
            $isInvoice = $sequence === 1;
            $effect = TelegramOutboundEffect::create([
                'effect_key' => $groupKey.':unit:'.$sequence,
                'effect_group_key' => $groupKey,
                'sequence' => $sequence,
                'effect_type' => TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
                'source_type' => Order::class,
                'source_id' => (string) $order->id,
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $order->customer->telegram_id,
                'queue' => TelegramOutboundEffectService::RECEIPT_QUEUE,
                'telegram_method' => $isInvoice ? 'sendPhoto' : 'sendMessage',
                'template_version' => TelegramOutboundEffectService::TEMPLATE_RECEIPT_INVOICE,
                'state' => TelegramOutboundEffectState::Pending,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'outcome_metadata' => [
                    'delivery_context' => 'receipt',
                    'unit_role' => $isInvoice ? 'invoice' : 'product_detail',
                    'receipt_chunk_index' => $sequence - 1,
                    'receipt_chunk_count' => $unitCount,
                ],
            ]);
            $first ??= $effect;
        }

        return $first;
    }

    private function paidOrderWithoutCredentials(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 24680, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'No Credential Product']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        return $order->fresh();
    }

    private function multiChunkPaidOrder(): Order
    {
        $customer = Customer::factory()->create(['telegram_id' => 98765, 'locale' => 'id']);
        $product = Product::factory()->create(['name' => 'Credential Bundle']);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 60,
        ]);
        Payment::factory()->for($order)->create([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);

        for ($index = 0; $index < 60; $index++) {
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'secret' => 'buyer'.$index.'@example.test|Password-'.$index.'-'.str_repeat('x', 70).'|MFA-'.$index,
            ]);
        }

        return $order->fresh();
    }

    private function notifierObservingTransactionLevel(int $expectedLevel): TelegramReceiptNotifier
    {
        $real = app(TelegramReceiptNotifier::class);
        $notifier = Mockery::mock(TelegramReceiptNotifier::class, [
            app(TelegramBotClient::class),
            app(TelegramLocale::class),
            app(CredentialDeliveryFormatter::class),
            app(CredentialDeliveryPresentation::class),
            app(TelegramCredentialDeliveryRenderer::class),
            app(TelegramMessageChunker::class),
            app(TelegramSupportContact::class),
            app(PaidInvoiceRenderer::class),
            app(PaidInvoiceOrderPresentationAdapter::class),
            app(TelegramOutboundEffectLedgerProtocol::class),
            app(TelegramUiIcons::class),
            app(TelegramProductNameRenderer::class),
        ])->makePartial();
        $notifier->shouldReceive('receiptPlan')
            ->once()
            ->andReturnUsing(function (Order $order) use ($expectedLevel, $real): TelegramReceiptPlan {
                Assert::assertSame($expectedLevel, DB::transactionLevel());

                return $real->receiptPlan($order);
            });
        $notifier->shouldReceive('renderInvoiceUnit')
            ->once()
            ->andReturnUsing(function (Order $order) use ($expectedLevel, $real) {
                Assert::assertSame($expectedLevel, DB::transactionLevel());

                return $real->renderInvoiceUnit($order);
            });

        return $notifier;
    }

    /** @return array{0: Order, 1: list<string>} */
    private function paidBuyerReceiptOrder(string $credentialPath): array
    {
        if (! in_array($credentialPath, ['structured', 'legacy'], true)) {
            throw new \InvalidArgumentException('Unsupported buyer credential path.');
        }

        $isStructured = $credentialPath === 'structured';
        $telegramId = $isStructured ? 332001 : 332002;
        $tutorial = 'Issue 332 '.ucfirst($credentialPath).' tutorial';
        $recoveryKey = 'Recovery-Key-332-'.$credentialPath;
        $quantity = $isStructured ? 2 : 1;
        $total = $quantity * 10_000;
        $customer = Customer::factory()->create([
            'telegram_id' => $telegramId,
            'locale' => 'id',
        ]);
        $product = Product::factory()->create([
            'name' => 'Issue 332 '.ucfirst($credentialPath).' Bundle',
        ]);
        $order = Order::factory()->for($customer)->create([
            'order_status' => OrderStatus::Completed,
            'payment_status' => PaymentStatus::Paid,
            'total_amount' => $total,
        ]);
        $item = OrderItem::factory()->for($order)->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'delivery_note' => $tutorial,
            'delivery_note_snapshotted' => true,
            'quantity' => $quantity,
            'unit_price' => 10_000,
            'subtotal' => $total,
        ]);
        Payment::factory()->for($order)->create([
            'amount' => $total,
            'provider_total_payment' => $total,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
        ]);
        $order->forceFill([
            'credential_access_key_hash' => Hash::make($recoveryKey),
        ])->save();

        if ($isStructured) {
            $otpEmail = 'issue332-otp@example.test';
            $otpPassword = 'Issue332-otp-password';
            $otpUrl = 'https://otp.issue332.example.test/mail?opaque=issue332-otp';
            $authEmail = 'issue332-auth@example.test';
            $authPassword = 'Issue332-auth-password';
            $authToken = 'issue332-auth-token';

            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => StructuredCredentialPayload::fromValues(
                    $otpEmail,
                    $otpPassword,
                    CredentialAccessMethod::OtpMailLink,
                    $otpUrl,
                )->toArray(),
            ]);
            ProductStockItem::create([
                'product_id' => $product->id,
                'order_item_id' => $item->id,
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => StructuredCredentialPayload::fromValues(
                    $authEmail,
                    $authPassword,
                    CredentialAccessMethod::AuthToken,
                    $authToken,
                )->toArray(),
            ]);

            return [$order->fresh(), [
                $otpEmail,
                $otpPassword,
                $otpUrl,
                'otp.issue332.example.test',
                $authEmail,
                $authPassword,
                $authToken,
                $tutorial,
                $recoveryKey,
            ]];
        }

        $legacyEmail = 'issue332-legacy@example.test';
        $legacyPassword = 'Issue332-legacy-password';
        $legacyMfa = 'Issue332-legacy-mfa';
        $legacySecret = implode('|', [$legacyEmail, $legacyPassword, $legacyMfa]);
        ProductStockItem::create([
            'product_id' => $product->id,
            'order_item_id' => $item->id,
            'secret' => $legacySecret,
        ]);

        return [$order->fresh(), [
            $legacyEmail,
            $legacyPassword,
            $legacyMfa,
            $legacySecret,
            $tutorial,
            $recoveryKey,
        ]];
    }

    /** @return iterable<string, array{int}> */
    public static function definitePhotoRejectionStatuses(): iterable
    {
        yield 'bad request' => [400];
        yield 'forbidden' => [403];
    }

    /** @return iterable<string, array{int|string}> */
    public static function ambiguousPhotoFailures(): iterable
    {
        yield 'server error' => [500];
        yield 'transport error' => ['transport'];
    }

    /** @return iterable<string, array{TelegramOutboundEffectState}> */
    public static function unresolvedFirstPhotoStates(): iterable
    {
        yield 'failed' => [TelegramOutboundEffectState::Failed];
        yield 'unknown' => [TelegramOutboundEffectState::Unknown];
        yield 'manual review' => [TelegramOutboundEffectState::ManualReview];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function rejectedTelegramResponses(): iterable
    {
        yield 'ok false with numeric result' => [['ok' => false, 'result' => ['message_id' => 901]]];
        yield 'ok false without result' => [['ok' => false]];
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function acceptedTelegramResponsesWithoutAProvenMessageId(): iterable
    {
        yield 'missing result' => [['ok' => true]];
        yield 'empty result' => [['ok' => true, 'result' => []]];
        yield 'zero' => [['ok' => true, 'result' => ['message_id' => 0]]];
        yield 'empty string' => [['ok' => true, 'result' => ['message_id' => '']]];
        yield 'non numeric' => [['ok' => true, 'result' => ['message_id' => 'invalid']]];
    }
}
