<?php

// Synthetic oracle in P01 quarantine only; never accepts operator data or endpoints.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'disposable') {
    throw new RuntimeException('Synthetic checkout fixture refused');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Queue::fake();
config(['cache.default' => 'array', 'services.telegram.bot_token' => '', 'services.telegram.admin_telegram_chat_id' => null]);
App\Models\CustomerUsdtFundingPolicy::current();
App\Models\BalanceRestoreGate::current();
App\Models\ManualPaymentSetting::create(['is_active' => true, 'payment_mode' => 'manual']);
$serial = 700000;
function buyer(int $balance, ?int $telegramId = null): App\Models\Customer {
    global $serial;
    $customer = App\Models\Customer::factory()->create(['telegram_id' => $telegramId ?? ++$serial, 'first_name' => 'Synthetic P07']);
    if ($balance > 0) {
        app(App\Services\StoreBalance\StoreBalanceLedger::class)->creditTopUp($customer, $balance,
            'p07-fixture-'.$customer->id, 'p07-fixture:'.$customer->id);
    }
    return $customer;
}
function product(string $label, int $stock = 4, int $price = 15000, bool $dispatch = false): App\Models\Product {
    $product = App\Models\Product::factory()->create(['name' => 'Synthetic P07 '.$label,
        'stock' => 0, 'reserved_stock' => 0, 'price_amount' => $price, 'usdt_price_amount' => null,
        'reseller_price_amount' => null, 'reseller_usdt_price_amount' => null,
        'category_id' => null, 'product_family_id' => null, 'is_active' => true]);
    $items = array_map(fn ($index) => 'Synthetic P07 '.$label.' credential '.$index, range(1, $stock));
    app(App\Services\ProductStockIntake::class)->submit($product,
        App\Services\ProductStockIntakeSubmission::legacy($items), dispatchNotifications: $dispatch);
    return $product->fresh();
}
function cart(App\Models\Customer $customer, App\Models\Product $product, int $quantity = 1): App\Models\Cart {
    $cart = App\Models\Cart::create(['customer_id' => $customer->id, 'status' => 'active', 'expires_at' => now()->addDay()]);
    app(App\Services\CartService::class)->addItem($cart, $product, $quantity);
    return $cart;
}
function row($customer, $product, $cart): array {
    return ['customer_id' => $customer->id, 'product_id' => $product->id, 'cart_id' => $cart->id];
}
$fixtures = [];
if (getenv('P25_NOTIFICATION_SMOKE') === '1') {
    // Snapshot while the disposable database genuinely has no customers.
    $emptyAudienceProduct = product('p25-empty-audience', 1);
    $emptyMovement = App\Models\InventoryMovement::where('product_id', $emptyAudienceProduct->id)
        ->orderByDesc('id')->firstOrFail();
    $emptyEvent = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class)->materialize($emptyMovement->id);
    if ($emptyEvent === null || $emptyEvent->recipients_snapshotted_at === null
        || App\Models\StockNotificationRecipient::where('stock_notification_event_id', $emptyEvent->id)->exists()) {
        throw new RuntimeException('Synthetic empty audience snapshot unavailable');
    }
    $fixtures['p25_empty_stock_movement'] = $emptyMovement->id;
    $soleBuyer = buyer(50000, 1);
    $oneAudienceProduct = product('p25-one-audience', 1);
    $oneMovement = App\Models\InventoryMovement::where('product_id', $oneAudienceProduct->id)
        ->orderByDesc('id')->firstOrFail();
    $oneEvent = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class)->materialize($oneMovement->id);
    $oneRecipient = App\Models\StockNotificationRecipient::where('stock_notification_event_id', $oneEvent->id)->sole();
    $oneEffect = App\Models\TelegramOutboundEffect::where('source_type', App\Models\StockNotificationRecipient::class)
        ->where('source_id', (string) $oneRecipient->id)
        ->where('effect_type', App\Services\Telegram\TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)->sole();
    if ($oneEvent->recipients_snapshotted_at === null || $oneRecipient->customer_id !== $soleBuyer->id) {
        throw new RuntimeException('Synthetic complete one-customer audience unavailable');
    }
    $fixtures['p25_one_stock_audience'] = ['movement_id' => $oneMovement->id, 'effect_id' => $oneEffect->id];
    $sentProduct = product('p25-sent-audience', 1);
    $sentMovement = App\Models\InventoryMovement::where('product_id', $sentProduct->id)
        ->orderByDesc('id')->firstOrFail();
    $sentEvent = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class)->materialize($sentMovement->id);
    $sentRecipient = App\Models\StockNotificationRecipient::where('stock_notification_event_id', $sentEvent->id)->sole();
    $sentEffect = App\Models\TelegramOutboundEffect::where('source_type', App\Models\StockNotificationRecipient::class)
        ->where('source_id', (string) $sentRecipient->id)
        ->where('effect_type', App\Services\Telegram\TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)->sole();
    if ($sentEvent->recipients_snapshotted_at === null || $sentRecipient->customer_id !== $soleBuyer->id) {
        throw new RuntimeException('Synthetic sent audience unavailable');
    }
    $fixtures['p25_sent_stock_audience'] = ['movement_id' => $sentMovement->id, 'effect_id' => $sentEffect->id];
    $failedProduct = product('p25-failed-audience', 1);
    $failedMovement = App\Models\InventoryMovement::where('product_id', $failedProduct->id)
        ->orderByDesc('id')->firstOrFail();
    $failedEvent = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class)->materialize($failedMovement->id);
    $failedRecipient = App\Models\StockNotificationRecipient::where('stock_notification_event_id', $failedEvent->id)->sole();
    $failedEffect = App\Models\TelegramOutboundEffect::where('source_type', App\Models\StockNotificationRecipient::class)
        ->where('source_id', (string) $failedRecipient->id)
        ->where('effect_type', App\Services\Telegram\TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)->sole();
    if ($failedEvent->recipients_snapshotted_at === null || $failedRecipient->customer_id !== $soleBuyer->id) {
        throw new RuntimeException('Synthetic terminal audience unavailable');
    }
    $fixtures['p25_failed_stock_audience'] = ['movement_id' => $failedMovement->id, 'effect_id' => $failedEffect->id];
    foreach (range(2, 5) as $chat) {
        buyer(0, $chat);
    }
    foreach (['resolved', 'unknown'] as $outcome) {
        $manyProduct = product('p25-many-audience-'.$outcome, 1);
        $manyMovement = App\Models\InventoryMovement::where('product_id', $manyProduct->id)
            ->orderByDesc('id')->firstOrFail();
        $manyEvent = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class)->materialize($manyMovement->id);
        $manyRecipients = App\Models\StockNotificationRecipient::where('stock_notification_event_id', $manyEvent->id)
            ->orderBy('id')->get();
        if ($manyEvent->recipients_snapshotted_at === null || $manyRecipients->count() !== 5) {
            throw new RuntimeException('Synthetic complete multi-customer audience unavailable');
        }
        $manyEffects = App\Models\TelegramOutboundEffect::where('source_type', App\Models\StockNotificationRecipient::class)
            ->whereIn('source_id', $manyRecipients->pluck('id')->map(fn ($id) => (string) $id))
            ->where('effect_type', App\Services\Telegram\TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION)
            ->orderBy('source_id')->get();
        if ($manyEffects->count() !== 5) {
            throw new RuntimeException('Synthetic multi-customer handoff incomplete');
        }
        $fixtures['p25_many_stock_audience'][$outcome] = [
            'movement_id' => $manyMovement->id, 'event_id' => $manyEvent->id,
            'effects' => $manyEffects->map(fn ($effect) => ['id' => $effect->id, 'chat' => $effect->telegram_chat_id])->all(),
        ];
    }
    $partialProduct = product('p25-candidate-audience', 1, 15000, true);
    $partialMovement = App\Models\InventoryMovement::where('product_id', $partialProduct->id)
        ->orderByDesc('id')->firstOrFail();
    $partialEvent = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class)->register($partialMovement->id);
    if ($partialEvent === null) {
        throw new RuntimeException('Synthetic candidate event missing');
    }
    if ($partialEvent->recipients_snapshotted_at !== null) {
        throw new RuntimeException('Synthetic candidate already snapshotted');
    }
    $fixtures['p25_candidate_stock_audience'] = ['event_id' => $partialEvent->id, 'movement_id' => $partialMovement->id];
    config(['services.telegram.token' => 'synthetic-p25-token']);
    $broadcastService = app(App\Services\Telegram\TelegramBroadcastService::class);
    $queued = $broadcastService->queueAdminBroadcast('Synthetic P25 audience', [], 'p25-audience');
    $broadcast = App\Models\TelegramBroadcast::findOrFail($queued['broadcast_id']);
    $broadcastPipeline = app(App\Services\Telegram\AdminBroadcastDeliveryPipeline::class);
    (new ReflectionMethod($broadcastPipeline, 'ensureAdminBroadcastRecipients'))->invoke($broadcastPipeline, $broadcast);
    (new ReflectionMethod($broadcastPipeline, 'syncAdminBroadcastProjection'))->invoke($broadcastPipeline, $broadcast->id, true);
    config(['services.telegram.token' => '']);
    $broadcast->refresh();
    $broadcastRecipients = App\Models\TelegramBroadcastRecipient::where('broadcast_id', $broadcast->id)->get();
    $broadcastEffects = App\Models\TelegramOutboundEffect::where('source_type', App\Models\TelegramBroadcastRecipient::class)
        ->whereIn('source_id', $broadcastRecipients->pluck('id')->map(fn ($id) => (string) $id))
        ->where('effect_type', App\Services\Telegram\TelegramOutboundEffectService::TYPE_ADMIN_BROADCAST_RECIPIENT)->get();
    if ($broadcast->recipients_materialized_at === null || $broadcastRecipients->count() !== 5 || $broadcastEffects->count() !== 5) {
        throw new RuntimeException('Synthetic broadcast snapshot unavailable');
    }
    $fixtures['p25_broadcast_audience'] = ['id' => $broadcast->id,
        'effects' => $broadcastEffects->map(fn ($effect) => ['id' => $effect->id, 'chat' => $effect->telegram_chat_id])->all()];
}
foreach (['php', 'python'] as $language) {
    $customer = $language === 'python' && isset($soleBuyer) ? $soleBuyer : buyer(50000);
    $product = product('tier-'.$language);
    $product->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 12000, 'usdt_price_amount' => null]);
    $cart = cart($customer, $product, 2);
    $fixtures[$language] = row($customer, $product, $cart);
    if ($language === 'php') {
        $order = app(App\Services\OrderCheckoutService::class)->checkout($cart,
            App\Services\Checkout\CheckoutPaymentIntents::storeBalance(), str_repeat('a', 64));
        $fixtures['php']['order_id'] = $order->id;
        $item = $order->orderItems()->sole();
        $fixtures['expected'] = ['amount' => $order->total_amount, 'currency' => $order->currency,
            'payment_status' => $order->payment_status->value, 'order_status' => $order->order_status->value,
            'balance' => app(App\Services\StoreBalance\StoreBalanceLedger::class)->snapshot($customer)->balance(),
            'unit_price' => $item->unit_price, 'subtotal' => $item->subtotal,
            'tier_minimum' => $item->quantity_price_tier_minimum,
            'stock' => $product->fresh()->stock, 'reserved_stock' => $product->fresh()->reserved_stock];
    }
}
$customer = buyer(20000);
$product = product('same-wallet', 2);
$fixtures['wallet'] = [row($customer, $product, cart($customer, $product)), row($customer, $product, cart($customer, $product))];
$product = product('last-stock', 1);
foreach ([buyer(50000), buyer(50000)] as $customer) {
    $fixtures['last_stock'][] = row($customer, $product, cart($customer, $product));
}
foreach (['duplicate', 'stale', 'maintenance', 'rollback', 'release', 'pause', 'restore', 'foreign', 'empty_balance', 'retry_success', 'retry_exhausted', 'preflight'] as $name) {
    $customer = buyer($name === 'empty_balance' ? 0 : 50000);
    $product = product($name);
    $fixtures[$name] = row($customer, $product, cart($customer, $product));
}
$customer = buyer(50000);
$product = product('receipt-lost-ack');
$fixtures['receipt_lost'] = row($customer, $product, cart($customer, $product, 2));
$customer = buyer(50000);
$first = product('receipt-partial-a');
$second = product('receipt-partial-b');
$partialCart = cart($customer, $first);
app(App\Services\CartService::class)->addItem($partialCart, $second, 1);
$fixtures['receipt_partial'] = row($customer, $first, $partialCart);
$customer = buyer(50000);
$product = product('receipt-historical');
$fixtures['receipt_historical'] = row($customer, $product, cart($customer, $product));
foreach (['navigation_qris', 'navigation_balance', 'navigation_race'] as $name) {
    $customer = buyer(50000);
    $customer->update(['locale' => 'id']);
    $product = product(str_replace('_', '-', $name));
    $fixtures[$name] = [
        'customer_id' => $customer->id,
        'telegram_id' => $customer->telegram_id,
        'product_id' => $product->id,
        'product_name' => $product->name,
        'stock' => $product->fresh()->stock,
    ];
}
$fixtures['key'] = hash_hmac('sha256', 'telegram-direct-checkout|42|700042|700042|99|checkout:choice', str_repeat('a', 64));
if (getenv('P20_API_SMOKE') === '1') {
    $fixtures['p20_legacy_cursor'] = rtrim(strtr(Illuminate\Support\Facades\Crypt::encryptString('12345'), '+/', '-_'), '=');
    $existing = App\Models\Customer::findOrFail($fixtures['foreign']['customer_id']);
    $fixtures['p20_legacy_key'] = app(App\Services\CustomerApi\CustomerApiCredentials::class)->generate($existing)->takeSecret();
    config(['services.credentials.identity_fingerprint_key' => 'p20-synthetic-identity-key']);
    if (Illuminate\Support\Facades\Artisan::call('credentials:backfill-identity-registry') !== 0) {
        throw new RuntimeException('Synthetic credential identity backfill failed');
    }
    $largeCustomer = buyer(50000);
    $largeProduct = App\Models\Product::factory()->create([
        'name' => 'Synthetic P20 large result', 'stock' => 0, 'reserved_stock' => 0,
        'price_amount' => 1, 'usdt_price_amount' => null, 'reseller_price_amount' => null,
        'reseller_usdt_price_amount' => null, 'category_id' => null, 'product_family_id' => null, 'is_active' => true,
        'delivery_note' => str_repeat('u', 2000),
    ]);
    $largeItems = array_map(fn ($index) => [
        'email' => 'p20-'.$index.'@example.invalid',
        'password' => str_repeat('p', 1024),
        'access_method' => 'auth_token',
        'access_value' => str_pad('p20-token-'.$index.'-', 3500, 't'),
    ], range(1, 100));
    foreach (array_chunk($largeItems, 20) as $chunk) {
        app(App\Services\ProductStockIntake::class)->submit($largeProduct,
            App\Services\ProductStockIntakeSubmission::structured($chunk), dispatchNotifications: false);
    }
    app(App\Services\ProductStockIntake::class)->submit($largeProduct,
        App\Services\ProductStockIntakeSubmission::structured([[
            'email' => 'p20-no-token@example.invalid', 'password' => 'synthetic-password',
            'access_method' => 'none', 'access_value' => null,
        ]]), dispatchNotifications: false);
    $fixtures['p20_large'] = row($largeCustomer, $largeProduct, cart($largeCustomer, $largeProduct));
}
$customerId = $fixtures['python']['customer_id'];
$orderId = '69400000-0000-4000-8000-000000000001';
$paymentId = '69400000-0000-4000-8000-000000000002';
$fixtures['p12_fingerprints'] = [
    'purchase' => (new App\Services\UsdtBalance\UsdtPurchase($customerId,
        App\Services\UsdtBalance\UsdtMicroAmount::fromUnits(101), $orderId, $paymentId))->fingerprint(),
    'refund' => (new App\Services\UsdtBalance\UsdtRefund($customerId,
        App\Services\UsdtBalance\UsdtMicroAmount::fromUnits(51), $orderId, $orderId, 42, $paymentId, true))->fingerprint(),
];
$writer = app(App\Services\MoneyReceipts\ExternalMoneyReceiptWriter::class);
$payment = new App\Models\Payment(['order_id' => $orderId,
    'provider' => 'manual', 'method' => 'BINANCE_PAY_ID', 'amount' => 101, 'currency' => 'USDT',
    'binance_receiver_type' => 'pay_id', 'binance_receiver_value' => '69400001']);
$payment->id = $paymentId;
$bscPayment = new App\Models\Payment;
$bscPayment->id = '69400000-0000-4000-8000-000000000003';
$receipts = [
    'binance' => $writer->receiptForBinancePayment($payment, 'p12-provider-transaction', 'USDT', 101),
    'bsc' => $writer->receiptForBscPayment($bscPayment, 56, '0x'.str_repeat('a', 64), 2,
        '0x'.str_repeat('b', 40), '101000000000000', 18),
];
foreach ($receipts as $rail => $receipt) {
    $fixtures['p12_receipts'][$rail] = [
        'canonical_key' => $receipt->canonicalKey(), 'fingerprint' => $receipt->fingerprint(),
        'provider' => $receipt->identity->provider(), 'identity_kind' => $receipt->identity->kind(),
        'account_namespace' => $receipt->identity->accountNamespace(), 'reference' => $receipt->identity->reference(),
        'chain_id' => $receipt->identity->chainId(), 'tx_hash' => $receipt->identity->txHash(),
        'log_index' => $receipt->identity->logIndex(), 'beneficiary' => $receipt->beneficiary,
        'currency' => $receipt->currency, 'amount_units' => $receipt->amountUnits, 'decimals' => $receipt->decimals,
        'target_type' => $receipt->target->type->value, 'target_id' => $receipt->target->targetId,
    ];
}
if (getenv('P19_AUTH_SMOKE') === '1') {
    config(['hashing.bcrypt.rounds' => 4]);
    $password = 'Synthetic-Auth42!';
    foreach (['raw', 'sha384-v1'] as $scheme) {
        $customer = App\Models\Customer::factory()->create([
            'telegram_id' => null, 'email' => 'p19-'.$scheme.'@example.invalid',
            'email_verified_at' => now(),
        ]);
        $input = $scheme === 'raw' ? $password : base64_encode(hash('sha384', "customer-password-v1\0".$password, true));
        $customer->forceFill(['password' => Illuminate\Support\Facades\Hash::make($input), 'password_scheme' => $scheme])->save();
        $fixtures['auth'][$scheme] = ['id' => $customer->id, 'email' => $customer->email];
    }
    $admin = App\Models\User::factory()->create(['email' => 'p19-admin@example.invalid', 'role' => 'owner',
        'password' => Illuminate\Support\Facades\Hash::make($password)]);
    $guest = App\Models\Customer::factory()->create(['telegram_id' => null, 'email' => null, 'email_verified_at' => null]);
    $guestOrder = app(App\Services\OrderCheckoutService::class)->checkout(cart($guest, product('p19-guest')),
        App\Services\Checkout\CheckoutPaymentIntents::manualQris(), 'p19-guest-order');
    $guestOrder = app(App\Services\OrderStateMachine::class)->completeManualOrder($guestOrder);
    $recovery = app(App\Storefront\StorefrontOrderAccess::class)->issue($guestOrder);
    $fixtures['auth']['guest'] = ['id' => $guest->id, 'order_id' => $guestOrder->id,
        'invoice' => $guestOrder->invoice_number, 'key' => $recovery];
    $fixtures['auth']['admin'] = ['id' => $admin->id, 'email' => $admin->email];
    $fixtures['auth']['password'] = $password;
    $fixtures['auth']['balance_cursor'] = app(App\Services\Customer\CustomerBalanceActivityCursorToken::class)->storeBalanceToken(
        $fixtures['auth']['raw']['id'],
        App\Services\StoreBalance\StoreBalanceActivityCursor::entry(
            \Carbon\CarbonImmutable::parse('2026-01-02 09:10:11.123456', 'Asia/Jakarta'), 42));
    $fixtures['auth']['signed_url'] = Illuminate\Support\Facades\URL::temporarySignedRoute('storefront.shop.orders.show',
        now()->addMinutes(15), ['order' => App\Models\Order::where('checkout_key', str_repeat('a', 64))->value('id')]);
}

if (getenv('P21_STOREFRONT_SMOKE') === '1') {
    $activityCustomer = App\Models\Customer::findOrFail($fixtures['auth']['raw']['id']);
    Illuminate\Support\Facades\Date::setTestNow(now()->addSecond());
    try {
        for ($i = 1; $i <= 27; $i++) {
            app(App\Services\StoreBalance\StoreBalanceLedger::class)->creditTopUp(
                $activityCustomer, 1000 + $i, 'p21-activity-'.$i, 'p21-idr-credit-'.$i);
            app(App\Services\UsdtBalance\CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
                $activityCustomer,
                new App\Services\UsdtBalance\VerifiedUsdtFunding(
                    $activityCustomer->id, App\Services\UsdtBalance\UsdtMicroAmount::fromUnits(1000000 + $i),
                    'p21-usdt-'.$i, 'p21-receipt-'.$i, 'v1'),
                'p21-usdt-credit-'.$i);
        }
    } finally {
        Illuminate\Support\Facades\Date::setTestNow();
    }
}
if (getenv('P14_EXTERNAL_SMOKE') === '1') {
    Illuminate\Support\Facades\DB::table('manual_payment_settings')->where('is_active', true)
        ->update(['binance_order_id_compatibility_ready_at' => now()]);
    app(App\Services\Binance\BinanceOrderIdActivation::class)->activate();
    Illuminate\Support\Facades\DB::table('manual_payment_settings')->where('is_active', true)
        ->update(['binance_verification_mode' => 'amount_unique']);
    $fixtures['external'] = [];
    foreach (['binance_unique', 'binance_order', 'binance_compat', 'binance_duplicate',
        'binance_reversed', 'binance_stale', 'binance_late', 'binance_race',
        'binance_rotation',
        'binance_alias_first', 'binance_alias_second',
        'bsc_discovery', 'bsc_hash', 'bsc_duplicate', 'bsc_parent', 'bsc_stale',
        'bsc_bad_token', 'bsc_bad_receiver', 'bsc_bad_amount',
        'bsc_late', 'bsc_rotation', 'bsc_race', 'navigation_binance', 'navigation_bsc'] as $name) {
        $customer = buyer(0);
        $customer->update(['locale' => 'id']);
        $product = product('p14-'.$name);
        // A current catalog price is mutable; no Order/Payment economics are rewritten.
        $product->update(['usdt_price_amount' => 1000000]);
        $fixtures['external'][$name] = row($customer, $product, cart($customer, $product));
    }
    foreach (['1', '3'] as $digit) {
        $receiver = App\Models\BscPaymentReceiver::factory()->manuallyVerified()->create([
            'address' => '0x'.str_repeat($digit, 40),
            'address_normalized' => '0x'.str_repeat($digit, 40),
            'token_contract' => '0x55d398326f99059ff775485246999027b3197955',
            'is_active' => $digit === '1',
        ]);
        $fixtures['external_receivers'][] = $receiver->id;
    }
}
if (getenv('P16_USDT_SMOKE') === '1') {
    foreach (['purchase', 'automatic', 'stale', 'frozen', 'race', 'reversal'] as $name) {
        $customer = buyer($name === 'automatic' ? 1000 : 50000);
        $product = product('p16-'.$name, 4);
        $product->update(['usdt_price_amount' => 1000001]);
        app(App\Services\UsdtBalance\CustomerUsdtBalanceLedger::class)->creditVerifiedTopUp(
            $customer,
            new App\Services\UsdtBalance\VerifiedUsdtFunding(
                $customer->id, App\Services\UsdtBalance\UsdtMicroAmount::fromUnits(1500000),
                'p16-topup-'.$customer->id, 'p16-receipt-'.$customer->id, 'v1'),
            'p16-credit-'.$customer->id,
        );
        $fixtures['p16'][$name] = row($customer, $product, cart($customer, $product));
        if ($name === 'race') {
            $fixtures['p16']['race_second'] = row($customer, $product, cart($customer, $product));
        }
    }
}
if (getenv('P17_USDT_SMOKE') === '1') {
    $customer = buyer(0);
    $product = product('p17-bsc');
    $product->update(['usdt_price_amount' => 600000]);
    $owner = App\Models\User::factory()->create(['role' => App\Enums\UserRole::Owner]);
    $registry = app(App\Services\UsdtReceivers\UsdtDepositReceiverRegistry::class);
    $registration = $registry->register($customer, '0x'.str_repeat('1', 40), $owner);
    $receiver = $registry->manualVerify($registration->receiver, true, $owner);
    $fixtures['p17_bsc'] = row($customer, $product, cart($customer, $product));
    $fixtures['p17_bsc']['receiver_id'] = $receiver->id;
    $fixtures['p17_bsc']['telegram_id'] = $customer->telegram_id;
}
if (getenv('P25_NOTIFICATION_SMOKE') === '1') {
    config(['app.name' => 'LhieStore']);
    // Redacted copy oracle only: these values are never persisted as grants.
    $authNotifications = [
        'magic' => new App\Notifications\CustomerLoginLinkNotification(route('customer.login-link.show').'#token=[REDACTED]'),
    ];
    foreach ([App\Enums\CustomerAuthTokenPurpose::EmailVerification,
        App\Enums\CustomerAuthTokenPurpose::EmailOtp, App\Enums\CustomerAuthTokenPurpose::PasswordReset] as $purpose) {
        $authNotifications[$purpose->value] = new App\Notifications\CustomerAuthMailNotification($purpose, '[REDACTED]');
    }
    foreach ($authNotifications as $purpose => $notification) {
        $copy = $notification->toMail(new stdClass);
        $fixtures['p25_auth_copy'][$purpose] = ['subject' => $copy->subject, 'greeting' => $copy->greeting,
            'intro' => $copy->introLines, 'outro' => $copy->outroLines,
            'action' => $copy->actionText, 'url' => $copy->actionUrl];
        $fixtures['p25_auth_html'][$purpose] = (string) $copy->render();
    }
    Illuminate\Support\Facades\Cache::forever('sales-proof-posts:published-count', 37);
    $fixtures['p25_counter_floor'] = app(App\Services\Telegram\SalesProofPostNumbering::class)->publishedCount();
    $historicalOrder = App\Models\Order::findOrFail($fixtures['php']['order_id']);
    $formatter = app(App\Services\Telegram\SalesProofPostFormatter::class);
    $presentation = app(App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter::class)->present($historicalOrder);
    $fixtures['p25_sales_proof_copy'] = [
        'v1' => $formatter->formatLegacy($historicalOrder, 37),
        'v2' => $formatter->format($presentation, 37, 2),
    ];
    $longBuyer = buyer(50000);
    $longProduct = product('p25-historical');
    $longProduct->update(['name' => 'Historical '.str_repeat('P', 80), 'icon' => '📦']);
    $longOrder = app(App\Services\OrderCheckoutService::class)->checkout(
        cart($longBuyer, $longProduct), App\Services\Checkout\CheckoutPaymentIntents::storeBalance(),
        hash('sha256', 'p25-long-historical'));
    $fixtures['p25_sales_proof_long'] = [
        'order_id' => $longOrder->id,
        'v1' => $formatter->formatLegacy($longOrder, 37),
        'v2' => $formatter->format(
            app(App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter::class)->present($longOrder), 37, 2),
    ];
    App\Models\Customer::whereKey($fixtures['python']['customer_id'])->update(['telegram_id' => 1]);
    $fixtures['p25_supplier_health'] = [];
    foreach (['<b>Synthetic supplier health</b>', '<b>Uncertain delivery</b>'] as $message) {
        $event = App\Models\SupplierHealthEvent::create(['message' => $message, 'created_at' => now()]);
        $effect = app(App\Services\Telegram\TelegramOutboundEffectService::class)->recordSupplierHealth($event, '1');
        $fixtures['p25_supplier_health'][] = ['effect_id' => $effect->id, 'message' => $message];
    }
    Illuminate\Support\Facades\DB::table('supplier_currency_rates')->insertOrIgnore([
        'currency' => 'USD', 'exchange_rate_idr' => 1000, 'version' => 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $healthRate = Illuminate\Support\Facades\DB::table('supplier_currency_rates')
        ->where('currency', 'USD')->value('exchange_rate_idr');
    $healthAttributes = [
        'name' => 'Synthetic & health', 'provider' => 'prodseller', 'api_key' => 'fixture-health-key',
        'currency' => 'USDT', 'currency_confirmed' => true, 'exchange_rate_idr' => $healthRate,
        'balance_micros' => 1000000, 'reserved_balance_micros' => 0,
        'low_balance_threshold_micros' => 1000000, 'is_active' => true,
        'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(5), 'balance_observed_at' => now(),
    ];
    $healthPhp = App\Models\Supplier::forceCreate($healthAttributes);
    $healthCandidate = App\Models\Supplier::forceCreate($healthAttributes);
    Illuminate\Support\Facades\DB::table('supplier_health_scans')->where('id', 1)->update([
        'supplier_cursor' => $healthPhp->id - 1,
    ]);
    config(['services.telegram.admin_telegram_chat_id' => '1']);
    app(App\Services\Suppliers\SupplierHealthService::class)->materialize(1);
    config(['services.telegram.admin_telegram_chat_id' => null]);
    $healthEvent = App\Models\SupplierHealthEvent::where('supplier_id', $healthPhp->id)->firstOrFail();
    $fixtures['p25_health_episode'] = ['supplier_id' => $healthCandidate->id,
        'message_sha256' => hash('sha256', $healthEvent->message)];
    $healthReviewSupplier = App\Models\Supplier::forceCreate(array_merge($healthAttributes, [
        'name' => 'Synthetic review', 'balance_micros' => 10000000,
    ]));
    $healthItem = App\Models\OrderItem::factory()->create(['local_quantity' => 0, 'supplier_quantity' => 1]);
    $healthFulfillment = App\Models\SupplierFulfillment::create([
        'order_id' => $healthItem->order_id, 'order_item_id' => $healthItem->id, 'quantity' => 1,
        'state' => 'review', 'maximum_cost_idr' => 5000,
        'sources_snapshot' => [['supplier_id' => $healthReviewSupplier->id]],
        'reserved_supplier_id' => $healthReviewSupplier->id,
    ]);
    $healthCatalog = App\Models\SupplierCatalogItem::create([
        'supplier_id' => $healthReviewSupplier->id, 'external_id' => (string) Illuminate\Support\Str::uuid(),
        'name' => 'Synthetic health SKU', 'unit_price_micros' => 1000000,
        'stock' => 10, 'orderable' => true, 'requires_input' => false,
        'synced_at' => now(), 'price_tiers' => [], 'bulk_discounts' => [],
    ]);
    $healthPurchase = App\Models\SupplierPurchase::create([
        'supplier_fulfillment_id' => $healthFulfillment->id, 'supplier_id' => $healthReviewSupplier->id,
        'supplier_catalog_item_id' => $healthCatalog->id, 'reference' => (string) Illuminate\Support\Str::uuid(),
        'quantity' => 1, 'expected_cost_micros' => 1000000, 'exchange_rate_idr' => $healthRate,
        'state' => 'ambiguous', 'last_error' => 'dispatch_outcome_unknown',
    ]);
    Illuminate\Support\Facades\DB::table('supplier_health_reviews')->insert([
        'supplier_fulfillment_id' => $healthFulfillment->id, 'active_identities' => '[]',
    ]);
    $fixtures['p25_health_episode']['review'] = ['supplier_id' => $healthReviewSupplier->id,
        'fulfillment_id' => $healthFulfillment->id, 'purchase_id' => $healthPurchase->id,
        'invoice' => $healthItem->order->invoice_number];
    $healthPhpReviewSupplier = App\Models\Supplier::forceCreate(array_merge($healthAttributes, [
        'name' => 'Synthetic review', 'balance_micros' => 10000000,
    ]));
    $healthPhpItem = App\Models\OrderItem::factory()->create(['local_quantity' => 0, 'supplier_quantity' => 1]);
    $healthPhpFulfillment = App\Models\SupplierFulfillment::create([
        'order_id' => $healthPhpItem->order_id, 'order_item_id' => $healthPhpItem->id, 'quantity' => 1,
        'state' => 'review', 'maximum_cost_idr' => 5000,
        'sources_snapshot' => [['supplier_id' => $healthPhpReviewSupplier->id]],
        'reserved_supplier_id' => $healthPhpReviewSupplier->id,
    ]);
    $healthPhpCatalog = $healthCatalog->replicate();
    $healthPhpCatalog->forceFill(['supplier_id' => $healthPhpReviewSupplier->id,
        'external_id' => (string) Illuminate\Support\Str::uuid()])->save();
    App\Models\SupplierPurchase::create([
        'supplier_fulfillment_id' => $healthPhpFulfillment->id, 'supplier_id' => $healthPhpReviewSupplier->id,
        'supplier_catalog_item_id' => $healthPhpCatalog->id, 'reference' => (string) Illuminate\Support\Str::uuid(),
        'quantity' => 1, 'expected_cost_micros' => 1000000, 'exchange_rate_idr' => $healthRate,
        'state' => 'ambiguous', 'last_error' => 'dispatch_outcome_unknown',
    ]);
    Illuminate\Support\Facades\DB::table('supplier_health_scans')->where('id', 1)->update([
        'supplier_cursor' => $healthPhpReviewSupplier->id - 1,
        'fulfillment_cursor' => $healthPhpFulfillment->id - 1,
    ]);
    config(['services.telegram.admin_telegram_chat_id' => '1']);
    app(App\Services\Suppliers\SupplierHealthService::class)->materialize(1);
    config(['services.telegram.admin_telegram_chat_id' => null]);
    $phpReview = App\Models\SupplierHealthEvent::where('supplier_id', $healthPhpReviewSupplier->id)->firstOrFail()->message;
    $phpReview = preg_replace(['/Invoice: [^\n]+/', '/Purchase #[0-9]+; SKU #[0-9]+/', '/Waktu: [^\n]+/'],
        ['Invoice: [invoice]', 'Purchase #[purchase]; SKU #[sku]', 'Waktu: [time]'], $phpReview);
    $fixtures['p25_health_episode']['review']['message_sha256'] = hash('sha256', $phpReview);
    $priceProduct = App\Models\Product::findOrFail($fixtures['preflight']['product_id']);
    $priceProduct->forceFill(['icon' => '📦', 'custom_emoji_id' => null, 'price_amount' => 12000,
        'usdt_price_amount' => 1000001])->save();
    $priceService = app(App\Services\Telegram\ProductPriceChangeNotificationService::class);
    $priceEvent = $priceService->createEventForCommittedUpdate($priceProduct,
        ['price_amount' => 15000, 'usdt_price_amount' => 2000002],
        ['price_amount' => 12000, 'usdt_price_amount' => 1000001]);
    $message = new ReflectionMethod($priceService, 'message');
    $fixtures['p25_price'] = ['event_id' => $priceEvent->id, 'messages' => []];
    foreach (['id', 'ms', 'en'] as $locale) {
        $fixtures['p25_price']['messages'][$locale] = $message->invoke($priceService, $priceEvent, $locale);
    }
    $milestone = new App\Mail\GuestOrderMilestoneMail(
        App\Enums\GuestOrderNotificationEvent::PaymentConfirmed, 'INV-P25-ORACLE', 'processing',
        'Sedang diproses', [['name' => '<Product>', 'quantity' => 2]], null,
        'http://127.0.0.1:18801/invoice', 'http://127.0.0.1:18801/akses-pesanan');
    $fixtures['p25_milestone_html'] = $milestone->render();
    $redactedGroups = [['product_name' => '<Product>', 'delivery_note' => 'Petunjuk penggunaan produk.', 'usage_guide' => null,
        'accounts' => [['label' => 'Data akun 1', 'kind' => 'structured', 'text' => null, 'parts' => null,
            'email' => '[REDACTED]', 'password' => '[REDACTED]', 'tutorial' => 'Petunjuk akun.',
            'access' => ['label' => 'Akses Token', 'value' => '[REDACTED]',
                'url' => 'https://reader.example.invalid/redacted', 'domain' => 'reader.example.invalid']]]]];
    $fixtures['p25_credential_html'] = (new App\Mail\OrderCredentialMail('INV-P25-REDACTED', $redactedGroups, [],
        'http://127.0.0.1:18801/invoice', '/akses-pesanan',
        'Login dari perangkat pribadi, ganti password setelah berhasil masuk, lalu pindahkan MFA/Authenticator ke perangkat Anda sendiri jika produk mendukungnya.'))->render();
    $guide = ['title' => 'Panduan <aman>', 'url' => 'https://docs.example.invalid/guide'];
    $variantGroups = [
        ['product_name' => 'Legacy & tutorial', 'delivery_note' => null, 'usage_guide' => $guide,
            'accounts' => [['label' => 'Data akun 1', 'kind' => 'legacy', 'text' => '[REDACTED]',
                'parts' => null, 'email' => null, 'password' => null, 'tutorial' => null, 'access' => null]]],
        ['product_name' => 'OTP Mail', 'delivery_note' => 'Panduan bawaan', 'usage_guide' => $guide,
            'accounts' => [
                ['label' => 'Data akun 1', 'kind' => 'structured', 'text' => null, 'parts' => null,
                    'email' => '[REDACTED]', 'password' => '[REDACTED]', 'tutorial' => null,
                    'access' => ['label' => 'OTP Mail', 'value' => null,
                        'url' => 'https://reader.example.invalid/redacted', 'domain' => 'reader.example.invalid']],
                ['label' => 'Data akun 2', 'kind' => 'structured', 'text' => null, 'parts' => null,
                    'email' => '[REDACTED]', 'password' => '[REDACTED]', 'tutorial' => 'Petunjuk khusus',
                    'access' => ['label' => 'Auth Token', 'value' => '[REDACTED]', 'url' => null, 'domain' => null]],
            ]],
    ];
    $fixtures['p25_credential_variant_html'] = (new App\Mail\OrderCredentialMail('INV-P25-REDACTED', $variantGroups, [$guide],
        'http://127.0.0.1:18801/invoice', '/akses-pesanan',
        'Login dari perangkat pribadi, ganti password setelah berhasil masuk, lalu pindahkan MFA/Authenticator ke perangkat Anda sendiri jika produk mendukungnya.'))->render();
    $reviewCustomer = buyer(50000);
    $reviewProduct = App\Models\Product::factory()->create(['name' => 'P25 numeric-stock review',
        'stock' => 1, 'reserved_stock' => 0, 'price_amount' => 10000, 'usdt_price_amount' => null,
        'category_id' => null, 'product_family_id' => null, 'is_active' => true]);
    $fixtures['p25_review'] = row($reviewCustomer, $reviewProduct, cart($reviewCustomer, $reviewProduct));
    $reviewCart = App\Models\Cart::findOrFail($fixtures['p25_review']['cart_id']);
    $reviewOrder = app(App\Services\OrderCheckoutService::class)->checkout($reviewCart,
        App\Services\Checkout\CheckoutPaymentIntents::storeBalance(), hash('sha256', 'p25-review'));
    $fixtures['p25_review']['order_id'] = $reviewOrder->id;
    foreach (['php', 'python'] as $balanceLanguage) {
    $balanceCustomer = App\Models\Customer::findOrFail($fixtures[$balanceLanguage]['customer_id']);
    $balanceChat = (string) $balanceCustomer->telegram_id;
    $fundingIntent = App\Models\StoreBalanceFundingIntent::create([
        'customer_id' => $balanceCustomer->id, 'chat_id' => $balanceChat, 'canonical_message_id' => 1,
        'policy_version' => 1, 'amount' => 5000, 'nonce' => Illuminate\Support\Str::random(32),
        'status' => App\Enums\StoreBalanceFundingIntentStatus::Confirmed,
        'expires_at' => now()->addMinutes(15), 'claimed_at' => now()]);
    $balanceTopUp = App\Models\StoreBalanceTopUp::create([
        'customer_id' => $balanceCustomer->id, 'store_balance_funding_intent_id' => $fundingIntent->id,
        'chat_id' => $balanceChat, 'canonical_message_id' => 1, 'locale' => 'en', 'provider' => 'manual',
        'merchant_ref' => 'P25-BALANCE-SYNTHETIC-'.$balanceLanguage, 'idempotency_key' => 'p25-balance-synthetic-'.$balanceLanguage,
        'currency' => 'IDR', 'amount' => 5000, 'status' => App\Enums\StoreBalanceTopUpStatus::AwaitingConfirmation,
        'provider_fee' => 0, 'provider_total_payment' => 5000, 'provider_payment_method' => 'QR_MANUAL',
        'provider_created_at' => now(), 'provider_expires_at' => now()->addMinutes(15),
        'expires_at' => now()->addMinutes(15), 'provider_create_state' => 'created']);
    app(App\Services\StoreBalance\TopUp\ManualStoreBalanceTopUpConfirmationService::class)->approve($balanceTopUp, $balanceCustomer, '1');
    if ($balanceLanguage === 'python') {
        $fixtures['p25_balance'] = ['top_up_id' => $balanceTopUp->id, 'status' => $balanceTopUp->fresh()->status->value];
    } else {
        $fixtures['p25_topup_channel_lost'] = $balanceTopUp->id;
        $fixtures['expected']['balance'] = app(App\Services\StoreBalance\StoreBalanceLedger::class)->snapshot($balanceCustomer)->balance();
    }
    }
    $channelSetting = App\Models\StoreChannelSetting::current();
    if (! $channelSetting->exists) {
        $channelSetting->save();
        $channelSetting->refresh();
    }
    $originalChannel = $channelSetting->only(['sales_proof_enabled', 'telegram_chat_id', 'channel_username']);
    $channelSetting->forceFill(['sales_proof_enabled' => true, 'telegram_chat_id' => '1', 'channel_username' => null])->save();
    $settledTopUp = $balanceTopUp->fresh();
    $channelEffect = app(App\Services\Telegram\TelegramOutboundEffectService::class)->recordBalanceTopUpChannelProof($settledTopUp);
    $topUpPresentation = app(App\Services\PaidInvoice\PaidInvoiceTopUpPresentationAdapter::class)->present($settledTopUp);
    $topUpCaption = app(App\Services\Telegram\SalesProofPostFormatter::class)->formatTopUp($topUpPresentation);
    $channelSetting->forceFill($originalChannel)->save();
    if ($channelEffect === null) {
        throw new RuntimeException('Synthetic top-up channel proof unavailable');
    }
    $fixtures['p25_topup_channel_proof'] = ['effect_id' => $channelEffect->id,
        'top_up_id' => $settledTopUp->id, 'caption_sha256' => hash('sha256', $topUpCaption)];
    Carbon\Carbon::setTestNow(now()->subDays(8));
    try {
        $oldCustomer = buyer(0);
        $oldIntent = App\Models\StoreBalanceFundingIntent::create([
            'customer_id' => $oldCustomer->id, 'chat_id' => (string) $oldCustomer->telegram_id,
            'canonical_message_id' => 1, 'policy_version' => 1, 'amount' => 5000,
            'nonce' => Illuminate\Support\Str::random(32), 'status' => App\Enums\StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => now()->addMinutes(15), 'claimed_at' => now()]);
        $oldTopUp = App\Models\StoreBalanceTopUp::create([
            'customer_id' => $oldCustomer->id, 'store_balance_funding_intent_id' => $oldIntent->id,
            'chat_id' => (string) $oldCustomer->telegram_id, 'canonical_message_id' => 1, 'locale' => 'en',
            'provider' => 'manual', 'merchant_ref' => 'P25-OLD-CHANNEL-PROOF',
            'idempotency_key' => 'p25-old-channel-proof', 'currency' => 'IDR', 'amount' => 5000,
            'status' => App\Enums\StoreBalanceTopUpStatus::AwaitingConfirmation, 'provider_fee' => 0,
            'provider_total_payment' => 5000, 'provider_payment_method' => 'QR_MANUAL',
            'provider_created_at' => now(), 'provider_expires_at' => now()->addMinutes(15),
            'expires_at' => now()->addMinutes(15), 'provider_create_state' => 'created']);
        app(App\Services\StoreBalance\TopUp\ManualStoreBalanceTopUpConfirmationService::class)
            ->approve($oldTopUp, $oldCustomer, '1');
        $fixtures['p25_topup_channel_old'] = $oldTopUp->id;
    } finally {
        Carbon\Carbon::setTestNow();
    }
    $instructionIntent = $fundingIntent->replicate();
    $instructionIntent->nonce = Illuminate\Support\Str::random(32);
    $instructionIntent->save();
    $instructionTopUp = $balanceTopUp->replicate();
    $instructionTopUp->fill(['store_balance_funding_intent_id' => $instructionIntent->id,
        'merchant_ref' => 'P25-INSTRUCTION-SYNTHETIC', 'idempotency_key' => 'p25-instruction-synthetic',
        'status' => App\Enums\StoreBalanceTopUpStatus::AwaitingConfirmation,
        'paid_at' => null, 'settled_at' => null, 'provider_create_state' => 'awaiting_confirmation',
        'expires_at' => now()->addHour(), 'provider_expires_at' => now()->addHour()]);
    $instructionTopUp->save();
    $instructionEffect = app(App\Services\Telegram\TelegramOutboundEffectService::class)
        ->recordBalanceTopUpInstructions($instructionTopUp);
    $instructionPayable = app(App\Services\StoreBalance\StoreBalanceTopUpService::class)
        ->presentation($instructionTopUp)->payable();
    if ($instructionEffect === null || $instructionPayable === null) {
        throw new RuntimeException('Synthetic top-up instruction unavailable');
    }
    $fixtures['p25_topup_instruction'] = ['effect_id' => $instructionEffect->id,
        'top_up_id' => $instructionTopUp->id, 'captions' => []];
    foreach (['id', 'ms', 'en'] as $locale) {
        $instruction = app(App\Services\Telegram\TelegramPaymentInstructionRenderer::class)
            ->balanceTopUp($instructionTopUp, $instructionPayable, 'synthetic', $locale, true);
        $fixtures['p25_topup_instruction']['captions'][$locale] = hash('sha256', $instruction->caption());
    }
    $lostInstructionIntent = $instructionIntent->replicate();
    $lostInstructionIntent->nonce = Illuminate\Support\Str::random(32);
    $lostInstructionIntent->save();
    $lostInstructionTopUp = $instructionTopUp->replicate();
    $lostInstructionTopUp->fill(['store_balance_funding_intent_id' => $lostInstructionIntent->id,
        'merchant_ref' => 'P25-INSTRUCTION-LOST', 'idempotency_key' => 'p25-instruction-lost']);
    $lostInstructionTopUp->save();
    $fixtures['p25_topup_instruction']['lost_top_up_id'] = $lostInstructionTopUp->id;
    $providerIntent = $instructionIntent->replicate();
    $providerIntent->nonce = Illuminate\Support\Str::random(32);
    $providerIntent->save();
    $providerInstruction = $instructionTopUp->replicate();
    $providerInstruction->fill(['store_balance_funding_intent_id' => $providerIntent->id,
        'merchant_ref' => 'P25-PROVIDER-INSTRUCTION', 'idempotency_key' => 'p25-provider-instruction',
        'provider' => 'sakurupiah', 'status' => App\Enums\StoreBalanceTopUpStatus::Payable,
        'provider_payment_method' => 'QRIS', 'provider_create_state' => 'created',
        'provider_qris_url' => 'https://sakurupiah.id/assets/qr/synthetic.png']);
    $providerInstruction->save();
    $providerEffect = app(App\Services\Telegram\TelegramOutboundEffectService::class)
        ->recordBalanceTopUpInstructions($providerInstruction);
    $providerPayable = app(App\Services\StoreBalance\StoreBalanceTopUpService::class)
        ->presentation($providerInstruction)->payable();
    if ($providerEffect === null || $providerPayable === null) {
        throw new RuntimeException('Synthetic provider instruction unavailable');
    }
    $fixtures['p25_topup_instruction']['provider_effect_id'] = $providerEffect->id;
    foreach (['id', 'ms', 'en'] as $locale) {
        $instruction = app(App\Services\Telegram\TelegramPaymentInstructionRenderer::class)
            ->balanceTopUp($providerInstruction, $providerPayable, 'synthetic', $locale, false);
        $fixtures['p25_topup_instruction']['provider_captions'][$locale] = hash('sha256', $instruction->caption());
    }
    $payloadIntent = $instructionIntent->replicate();
    $payloadIntent->fill(['nonce' => Illuminate\Support\Str::random(32), 'amount' => 20000]);
    $payloadIntent->save();
    $payloadInstruction = $providerInstruction->replicate();
    $payloadInstruction->fill(['store_balance_funding_intent_id' => $payloadIntent->id,
        'merchant_ref' => 'P25-PAYLOAD-INSTRUCTION', 'idempotency_key' => 'p25-payload-instruction',
        'amount' => 20000, 'provider_fee' => 450, 'provider_total_payment' => 20450,
        'provider_qris_url' => null, 'provider_qris_payload' => '0002010102125303360540820450.005802ID63045B6D']);
    $payloadInstruction->save();
    $payloadEffect = app(App\Services\Telegram\TelegramOutboundEffectService::class)
        ->recordBalanceTopUpInstructions($payloadInstruction);
    $payloadPayable = app(App\Services\StoreBalance\StoreBalanceTopUpService::class)
        ->presentation($payloadInstruction)->payable();
    if ($payloadEffect === null || $payloadPayable === null) {
        throw new RuntimeException('Synthetic QR payload instruction unavailable');
    }
    $fixtures['p25_topup_instruction']['payload_effect_id'] = $payloadEffect->id;
    foreach (['id', 'ms', 'en'] as $locale) {
        $instruction = app(App\Services\Telegram\TelegramPaymentInstructionRenderer::class)
            ->balanceTopUp($payloadInstruction, $payloadPayable, 'synthetic', $locale, false);
        $fixtures['p25_topup_instruction']['payload_captions'][$locale] = hash('sha256', $instruction->caption());
    }
    $compensationIntent = $fundingIntent->replicate();
    $compensationIntent->nonce = Illuminate\Support\Str::random(32);
    $compensationIntent->save();
    $compensationTopUp = $balanceTopUp->replicate();
    $compensationTopUp->fill(['store_balance_funding_intent_id' => $compensationIntent->id,
        'merchant_ref' => 'P25-COMPENSATION-SYNTHETIC', 'idempotency_key' => 'p25-compensation-synthetic',
        'status' => App\Enums\StoreBalanceTopUpStatus::Expired, 'paid_at' => null, 'settled_at' => null]);
    $compensationTopUp->save();
    $compensationOwner = App\Models\User::factory()->create(['role' => App\Enums\UserRole::Owner]);
    $fixtures['p25_compensation'] = ['top_up_id' => $compensationTopUp->id, 'owner_id' => $compensationOwner->id];
    $missingCompensationIntent = $compensationIntent->replicate();
    $missingCompensationIntent->nonce = Illuminate\Support\Str::random(32);
    $missingCompensationIntent->save();
    $missingCompensationTopUp = $compensationTopUp->replicate();
    $missingCompensationTopUp->fill(['store_balance_funding_intent_id' => $missingCompensationIntent->id,
        'merchant_ref' => 'P25-COMPENSATION-MISSING', 'idempotency_key' => 'p25-compensation-missing',
        'chat_id' => ' 1 ']);
    $missingCompensationTopUp->save();
    $fixtures['p25_compensation']['missing_top_up_id'] = $missingCompensationTopUp->id;
    $alertIntent = $compensationIntent->replicate();
    $alertIntent->nonce = Illuminate\Support\Str::random(32);
    $alertIntent->save();
    $alertTopUp = $compensationTopUp->replicate();
    $alertTopUp->fill(['store_balance_funding_intent_id' => $alertIntent->id,
        'merchant_ref' => 'P25-OWNER-ALERT', 'idempotency_key' => 'p25-owner-alert',
        'status' => App\Enums\StoreBalanceTopUpStatus::RecoveryPending,
        'provider_create_last_error' => 'Synthetic capi_'.str_repeat('a', 32).'.'.str_repeat('b', 64)
            .' https://example.invalid/bot'.str_repeat('c', 30).'/sendMessage fixture-'.str_repeat('s', 24)]);
    $alertTopUp->save();
    config(['services.telegram.admin_telegram_chat_id' => '1', 'services.telegram.token' => 'fixture-'.str_repeat('s', 24)]);
    $alertEffect = app(App\Services\Telegram\TelegramOutboundEffectService::class)->recordBalanceTopUpOwnerAlert($alertTopUp);
    $alertPipeline = app(App\Services\Telegram\BalanceTopUpOwnerAlertDeliveryPipeline::class);
    $alertMessage = (new ReflectionMethod($alertPipeline, 'message'))->invoke($alertPipeline, $alertTopUp->fresh());
    config(['services.telegram.admin_telegram_chat_id' => null]);
    if ($alertEffect === null) {
        throw new RuntimeException('Synthetic owner alert unavailable');
    }
    $fixtures['p25_owner_alert'] = ['top_up_id' => $alertTopUp->id, 'effect_id' => $alertEffect->id,
        'message_sha256' => hash('sha256', $alertMessage)];
    $warningIcon = App\Models\TelegramUiIcon::where('key', 'message.warning')->firstOrFail();
    $originalIconId = $warningIcon->custom_emoji_id;
    $warningIcon->forceFill(['custom_emoji_id' => '1234567890123456789'])->save();
    app(App\Services\Telegram\TelegramUiIcons::class)->forget();
    $customPipeline = app(App\Services\Telegram\BalanceTopUpOwnerAlertDeliveryPipeline::class);
    $customMessage = (new ReflectionMethod($customPipeline, 'message'))->invoke($customPipeline, $alertTopUp->fresh());
    $customWire = app(App\Services\Telegram\CustomEmojiCapability::class)->textAndEntities($customMessage);
    $fixtures['p25_owner_alert']['custom_icon'] = ['id' => $warningIcon->id,
        'text_sha256' => hash('sha256', $customWire['text']), 'entities' => $customWire['entities']];
    $warningIcon->forceFill(['custom_emoji_id' => $originalIconId])->save();
    app(App\Services\Telegram\TelegramUiIcons::class)->forget();
    config(['services.telegram.token' => '']);
    $lostAlertIntent = $alertIntent->replicate();
    $lostAlertIntent->nonce = Illuminate\Support\Str::random(32);
    $lostAlertIntent->save();
    $lostAlertTopUp = $alertTopUp->replicate();
    $lostAlertTopUp->fill(['store_balance_funding_intent_id' => $lostAlertIntent->id,
        'merchant_ref' => 'P25-OWNER-ALERT-LOST', 'idempotency_key' => 'p25-owner-alert-lost',
        'provider_create_last_error' => null]);
    $lostAlertTopUp->save();
    $fixtures['p25_owner_alert']['lost_top_up_id'] = $lostAlertTopUp->id;
    $proofCustomer = buyer(0);
    $proofIntent = App\Models\StoreBalanceFundingIntent::create([
        'customer_id' => $proofCustomer->id, 'chat_id' => (string) $proofCustomer->telegram_id,
        'canonical_message_id' => 1, 'policy_version' => 1, 'amount' => 5000,
        'nonce' => Illuminate\Support\Str::random(32), 'status' => App\Enums\StoreBalanceFundingIntentStatus::Confirmed,
        'expires_at' => now()->addMinutes(60), 'claimed_at' => now()]);
    $proofTopUp = App\Models\StoreBalanceTopUp::create([
        'customer_id' => $proofCustomer->id, 'store_balance_funding_intent_id' => $proofIntent->id,
        'chat_id' => (string) $proofCustomer->telegram_id, 'canonical_message_id' => 1, 'locale' => 'en',
        'provider' => 'manual', 'merchant_ref' => 'P25-PROOF-SYNTHETIC', 'idempotency_key' => 'p25-proof-synthetic',
        'currency' => 'IDR', 'amount' => 5000, 'status' => App\Enums\StoreBalanceTopUpStatus::AwaitingConfirmation,
        'provider_fee' => 0, 'provider_total_payment' => 5000, 'provider_payment_method' => 'QR_MANUAL',
        'provider_created_at' => now(), 'provider_expires_at' => now()->addMinutes(60),
        'expires_at' => now()->addMinutes(60), 'provider_create_state' => 'created']);
    $proofPipeline = app(App\Services\Telegram\BalanceTopUpPaymentProofDeliveryPipeline::class);
    $proofLines = new ReflectionMethod($proofPipeline, 'lines');
    $fixtures['p25_balance_proof'] = ['customer_id' => $proofCustomer->id, 'top_up_id' => $proofTopUp->id,
        'caption' => implode("\n", $proofLines->invoke($proofPipeline, $proofTopUp->load('customer'), 'en'))];
    $lostIntent = $proofIntent->replicate();
    $lostIntent->nonce = Illuminate\Support\Str::random(32);
    $lostIntent->save();
    $lostTopUp = $proofTopUp->replicate();
    $lostTopUp->fill(['store_balance_funding_intent_id' => $lostIntent->id, 'provider' => ' MANUAL ',
        'merchant_ref' => 'P25-LOST-PROOF', 'idempotency_key' => 'p25-lost-proof',
        'payment_proof_file_id' => 'synthetic-lost-proof', 'payment_proof_submitted_at' => now(),
        'payment_proof_admin_chat_id' => '1']);
    $lostTopUp->save();
    $fixtures['p25_balance_proof']['lost_top_up_id'] = $lostTopUp->id;
    $unknownIntent = $lostIntent->replicate();
    $unknownIntent->nonce = Illuminate\Support\Str::random(32);
    $unknownIntent->save();
    $unknownTopUp = $lostTopUp->replicate();
    $unknownTopUp->fill(['store_balance_funding_intent_id' => $unknownIntent->id,
        'merchant_ref' => 'P25-UNKNOWN-PROOF', 'idempotency_key' => 'p25-unknown-proof']);
    $unknownTopUp->save();
    $unknownEffect = app(App\Services\Telegram\TelegramOutboundEffectService::class)
        ->recordBalanceTopUpPaymentProof($unknownTopUp);
    if ($unknownEffect === null) {
        throw new RuntimeException('Synthetic proof effect unavailable');
    }
    $fixtures['p25_balance_proof']['unknown_effect_id'] = $unknownEffect->id;
    $stockProduct = App\Models\Product::factory()->create(['name' => 'P25 Stock <Product>',
        'stock' => 0, 'reserved_stock' => 0, 'price_amount' => 15000, 'usdt_price_amount' => 1000001,
        'icon' => '📦', 'custom_emoji_id' => null, 'category_id' => null, 'product_family_id' => null,
        'description' => 'Petunjuk <aman>', 'description_ms' => 'Panduan Melayu', 'description_en' => 'English guide',
        'telegram_channel_image_path' => 'synthetic-stock.png', 'is_active' => true]);
    $stockProduct->quantityPriceTiers()->create(['minimum_quantity' => 2, 'price_amount' => 12000, 'usdt_price_amount' => 900001]);
    app(App\Services\ProductStockIntake::class)->submit($stockProduct,
        App\Services\ProductStockIntakeSubmission::legacy(['P25 synthetic stock one', 'P25 synthetic stock two', 'P25 synthetic stock three']));
    $movement = App\Models\InventoryMovement::where('product_id', $stockProduct->id)->orderByDesc('id')->firstOrFail();
    $stockService = app(App\Services\Telegram\TelegramProductNotificationService::class);
    $fixtures['p25_stock'] = ['product_id' => $stockProduct->id, 'movement_id' => $movement->id, 'messages' => []];
    foreach (['id', 'ms', 'en'] as $locale) {
        $customer = App\Models\Customer::findOrFail($fixtures['python']['customer_id']);
        $customer->forceFill(['locale' => $locale])->save();
        $batch = $stockService->stockAddedDeliveryBatch($stockProduct, 3, 0, 3, 0, 100);
        foreach ($batch['deliveries'] as $delivery) {
            if ($delivery['recipient_type'] === 'customer' && $delivery['telegram_id'] === '1') {
                $fixtures['p25_stock']['messages'][$locale] = $delivery['text'];
            }
        }
        $historicalBatch = $stockService->stockAddedDeliveryBatch($stockProduct, 3, 0, null, 0, 100);
        foreach ($historicalBatch['deliveries'] as $delivery) {
            if ($delivery['recipient_type'] === 'customer' && $delivery['telegram_id'] === '1') {
                $fixtures['p25_stock']['historical_messages'][$locale] = $delivery['text'];
            }
        }
    }
    $stockEligibility = new ReflectionMethod(App\Services\Telegram\StockNotificationDeliveryPipeline::class, 'isNotifiable');
    $stockPipeline = app(App\Services\Telegram\StockNotificationDeliveryPipeline::class);
    $fixtures['p25_stock']['eligibility'] = [];
    foreach ([
        ['adjustment', 1, 0, 1, false], ['adjustment', -1, 1, 0, true],
        ['adjustment', -1, 2, 1, false], ['availability', 1, 0, 1, false],
        ['availability', 1, 1, 2, false], ['availability', -1, 1, 0, true],
        ['sale', -1, 1, 0, true], ['sale', -1, 2, 1, false],
        ['release', -1, 1, 0, true], ['reservation', -1, 1, 0, true],
        ['sale', 1, 0, 1, false],
    ] as [$type, $quantity, $before, $after, $unavailable]) {
        $probe = (new App\Models\InventoryMovement())->forceFill(['type' => $type, 'quantity' => $quantity,
            'available_before' => $before, 'available_after' => $after, 'became_unavailable' => $unavailable]);
        $probe->setRelation('product', $stockProduct);
        $fixtures['p25_stock']['eligibility'][] = compact('type', 'quantity', 'before', 'after', 'unavailable')
            + ['expected' => $stockEligibility->invoke($stockPipeline, $probe)];
    }
}
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
