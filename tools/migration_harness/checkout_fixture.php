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
function buyer(int $balance): App\Models\Customer {
    global $serial;
    $customer = App\Models\Customer::factory()->create(['telegram_id' => ++$serial, 'first_name' => 'Synthetic P07']);
    if ($balance > 0) {
        app(App\Services\StoreBalance\StoreBalanceLedger::class)->creditTopUp($customer, $balance,
            'p07-fixture-'.$customer->id, 'p07-fixture:'.$customer->id);
    }
    return $customer;
}
function product(string $label, int $stock = 4, int $price = 15000): App\Models\Product {
    $product = App\Models\Product::factory()->create(['name' => 'Synthetic P07 '.$label,
        'stock' => 0, 'reserved_stock' => 0, 'price_amount' => $price, 'usdt_price_amount' => null,
        'reseller_price_amount' => null, 'reseller_usdt_price_amount' => null,
        'category_id' => null, 'product_family_id' => null, 'is_active' => true]);
    $items = array_map(fn ($index) => 'Synthetic P07 '.$label.' credential '.$index, range(1, $stock));
    app(App\Services\ProductStockIntake::class)->submit($product,
        App\Services\ProductStockIntakeSubmission::legacy($items), dispatchNotifications: false);
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
foreach (['php', 'python'] as $language) {
    $customer = buyer(50000);
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

if (getenv('P25_NOTIFICATION_SMOKE') === '1') {
    $receiptCustomer = App\Models\Customer::findOrFail($fixtures['python']['customer_id']);
    $receiptProduct = product('isolation-receipt', 4, 1000);
    foreach (['mail', 'notifications'] as $queue) {
        $fixtures['p25']['isolation_receipt_'.$queue] = row(
            $receiptCustomer, $receiptProduct, cart($receiptCustomer, $receiptProduct),
        );
    }
    $context = [
        'app_name' => config('app.name'),
        'product_name' => 'P25 Waitlist Parity & <Premium>',
        'available_stock' => 2,
        'product_url' => 'http://127.0.0.1:18801/produk/42?source=fixture&view=product',
        'unsubscribe_url' => '__LHIE_WAITLIST_UNSUBSCRIBE_URL__',
    ];
    $mail = (new App\Mail\ProductWaitlistRestockedMail(
        $context['product_name'], $context['available_stock'], $context['product_url'], $context['unsubscribe_url'],
    ))->build();
    $fixtures['p25']['waitlist_restock_context'] = $context;
    $fixtures['p25']['waitlist_restock_mail'] = [
        'subject' => $mail->subject,
        'text' => view($mail->textView, $mail->buildViewData())->render(),
        'html' => $mail->render(),
    ];

    $fixtures['p25']['app_name'] = config('app.name');
    $uiIconRegistry = app(App\Services\Telegram\TelegramUiIcons::class);
    App\Models\TelegramUiIcon::query()->where('key', 'message.cart')->update([
        'custom_emoji_id' => '987654321',
        'fallback_emoji' => '🛍️',
    ]);
    $uiIconRegistry->forget();
    $emojiCapability = app(App\Services\Telegram\CustomEmojiCapability::class);
    $emojiCapability->markAvailable();
    $fixtures['p25']['ui_icons'] = $uiIconRegistry->all()->map(static fn (
        App\Models\TelegramUiIcon $icon,
    ): array => [
        'key' => (string) $icon->key,
        'source_emoji' => $icon->source_emoji === null ? null : (string) $icon->source_emoji,
        'fallback_emoji' => (string) $icon->fallback_emoji,
        'custom_emoji_id' => $icon->custom_emoji_id === null ? null : (string) $icon->custom_emoji_id,
    ])->values()->all();

    app()->instance(App\Services\Telegram\TelegramBotIdentity::class, new class extends App\Services\Telegram\TelegramBotIdentity {
        public function __construct() {}

        public function username(): ?string
        {
            return 'SyntheticTestBot';
        }

        public function startUrl(string $payload = 'landing'): ?string
        {
            return 'https://t.me/SyntheticTestBot?start='.rawurlencode($payload);
        }
    });
    $botClient = app(App\Services\Telegram\TelegramBotClient::class);
    $normalizer = new ReflectionMethod($botClient, 'normalizeCustomEmojiText');
    $normalizer->setAccessible(true);
    $sourceWire = static function (
        string $field,
        string $content,
        array $markup,
        string $chatId = '1',
        ?string $parseMode = 'HTML',
    ) use ($botClient, $normalizer): array {
        $payload = ['chat_id' => $chatId];
        if ($field !== 'caption' || $content !== '') {
            $payload[$field] = $content;
        }
        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }
        if ($markup !== []) {
            $payload['reply_markup'] = $markup;
        }
        return $normalizer->invoke($botClient, $payload);
    };
    $adminOrderNotifier = app(App\Services\Telegram\TelegramAdminOrderNotifier::class);
    $adminPaidNotifier = app(App\Services\Telegram\TelegramAdminPaidOrderNotifier::class);
    $pendingMessage = new ReflectionMethod($adminOrderNotifier, 'pendingMessage');
    $pendingMessage->setAccessible(true);
    $expiredMessage = new ReflectionMethod($adminOrderNotifier, 'expiredMessage');
    $expiredMessage->setAccessible(true);
    $orderReplyMarkup = new ReflectionMethod($adminOrderNotifier, 'dashboardReplyMarkup');
    $orderReplyMarkup->setAccessible(true);
    $paidMessage = new ReflectionMethod($adminPaidNotifier, 'paidMessage');
    $paidMessage->setAccessible(true);
    $paidReplyMarkup = new ReflectionMethod($adminPaidNotifier, 'dashboardReplyMarkup');
    $paidReplyMarkup->setAccessible(true);
    $fulfillmentStatusLines = new ReflectionMethod($adminPaidNotifier, 'fulfillmentStatusLines');
    $fulfillmentStatusLines->setAccessible(true);
    $stockLines = new ReflectionMethod($adminPaidNotifier, 'stockLines');
    $stockLines->setAccessible(true);
    $adminCases = [];
    $adminTimestamp = Carbon\CarbonImmutable::parse('2026-11-11 12:34:00', config('app.timezone'));
    foreach ([
        'pending' => ['pending_payment', 'pending', $adminTimestamp->addHour(), null],
        'expired' => ['expired', 'expired', $adminTimestamp->subHour(), $adminTimestamp->subMinute()],
        'paid' => ['completed', 'paid', null, null],
        'review' => ['completed', 'paid', null, null],
    ] as $name => [$orderStatus, $paymentStatus, $expiresAt, $expiredAt]) {
        $orderId = (string) Illuminate\Support\Str::uuid();
        $itemId = (string) Illuminate\Support\Str::uuid();
        $paymentId = (string) Illuminate\Support\Str::uuid();
        $orderAttributes = [
            'id' => $orderId,
            'invoice_number' => 'INV-P25-ADMIN-'.$name,
            'order_status' => $orderStatus,
            'payment_status' => $paymentStatus,
            'total_amount' => 20501,
            'currency' => 'IDR',
            'api_order_claim_id' => null,
            'expires_at' => $expiresAt,
            'updated_at' => $adminTimestamp,
            'telegram_receipt_sent_at' => null,
        ];
        $paymentAttributes = [
            'id' => $paymentId,
            'order_id' => $orderId,
            'provider' => 'manual',
            'method' => 'QR_MANUAL',
            'currency' => 'IDR',
            'amount' => 20501,
            'status' => $paymentStatus,
            'provider_fee' => 100,
            'provider_total_payment' => null,
            'paid_at' => $paymentStatus === 'paid' ? $adminTimestamp->subHour() : null,
            'expired_at' => $expiredAt,
        ];
        $itemAttributes = [
            'id' => $itemId,
            'order_id' => $orderId,
            'product_id' => null,
            'product_name' => 'P25 synthetic <product> & edition',
            'product_icon' => '📦',
            'product_custom_emoji_id' => '987654322',
            'quantity' => 2,
        ];
        $customer = (new App\Models\Customer())->forceFill([
            'first_name' => 'Synthetic Buyer',
            'username' => null,
            'telegram_id' => null,
            'locale' => 'id',
        ]);
        $order = (new App\Models\Order())->forceFill($orderAttributes);
        $order->setRelation('customer', $customer);
        $order->setRelation('orderItems', collect([(new App\Models\OrderItem())->forceFill($itemAttributes)]));
        $order->setRelation('payments', collect([(new App\Models\Payment())->forceFill($paymentAttributes)]));
        $message = match ($name) {
            'pending' => $pendingMessage->invoke($adminOrderNotifier, $order),
            'expired' => $expiredMessage->invoke($adminOrderNotifier, $order),
            'paid' => $paidMessage->invoke($adminPaidNotifier, $order, false, null),
            'review' => $paidMessage->invoke($adminPaidNotifier, $order, true, null),
        };
        $markup = in_array($name, ['pending', 'expired'], true)
            ? $orderReplyMarkup->invoke($adminOrderNotifier, $order)
            : $paidReplyMarkup->invoke($adminPaidNotifier, $order);
        $case = [
            'kind' => match ($name) {
                'pending' => 'admin.order_pending_notification',
                'expired' => 'admin.order_expired_notification',
                'paid' => 'admin.order_paid_notification',
                'review' => 'admin.order_review_notification',
            },
            'order' => [
                ...$orderAttributes,
                'order_status' => $order->order_status->value,
                'payment_status' => $order->payment_status->value,
                'expires_at' => $expiresAt?->toIso8601String(),
                'updated_at' => $adminTimestamp->toIso8601String(),
                'username' => null,
                'first_name' => 'Synthetic Buyer',
                'telegram_id' => null,
            ],
            'payment' => [
                ...$paymentAttributes,
                'status' => $order->payments->first()->status->value,
                'paid_at' => $paymentAttributes['paid_at']?->toIso8601String(),
                'expired_at' => $expiredAt?->toIso8601String(),
                'payment_method_label' => null,
            ],
            'items' => [$itemAttributes + ['allocated' => 0]],
            'dashboard_url' => $name === 'pending' || $name === 'expired'
                ? $orderReplyMarkup->invoke($adminOrderNotifier, $order)['inline_keyboard'][0][0]['url']
                : $paidReplyMarkup->invoke($adminPaidNotifier, $order)['inline_keyboard'][0][0]['url'],
            'fulfillment_lines' => in_array($name, ['paid', 'review'], true)
                ? $fulfillmentStatusLines->invoke($adminPaidNotifier, $order)
                : [],
            'stocks' => in_array($name, ['paid', 'review'], true)
                ? $stockLines->invoke($adminPaidNotifier, $order)
                : [],
            'wire' => $sourceWire('text', $message, $markup),
        ];
        $adminCases[$name] = $case;
    }
    $fixtures['p25']['lifecycle_admin'] = ['cases' => $adminCases];
    $ownerAlert = app(App\Services\Telegram\BalanceTopUpOwnerAlertDeliveryPipeline::class);
    $ownerMessageMethod = new ReflectionMethod($ownerAlert, 'message');
    $ownerMessageMethod->setAccessible(true);
    $paidOwnerMessageMethod = new ReflectionMethod($ownerAlert, 'paidMessage');
    $paidOwnerMessageMethod->setAccessible(true);
    $ownerCustomer = (new App\Models\Customer())->forceFill([
        'id' => 707,
        'first_name' => 'P25 Synthetic Balance Buyer',
        'username' => 'p25_balance',
        'telegram_id' => '70000000001',
        'locale' => 'id',
    ]);
    $ownerPaidAt = Carbon\CarbonImmutable::parse('2026-11-11 09:14:00', config('app.timezone'));
    $ownerTopUpAttributes = [
        'id' => 707,
        'customer_id' => 707,
        'chat_id' => '70000000001',
        'locale' => 'id',
        'provider' => 'manual',
        'merchant_ref' => 'P25-BALANCE-SYNTHETIC',
        'currency' => 'IDR',
        'amount' => 10000,
        'status' => 'recovery_pending',
        'provider_total_payment' => 10500,
        'provider_payment_method' => 'QR_MANUAL',
        'paid_at' => $ownerPaidAt,
        'settled_at' => $ownerPaidAt,
        'provider_create_last_error' => 'https://api.telegram.org/bot12345678:synthetic-private-token-0123456789abcdefgh/sendMessage',
    ];
    $ownerTopUp = (new App\Models\StoreBalanceTopUp())->forceFill($ownerTopUpAttributes);
    $paidOwnerTopUp = (new App\Models\StoreBalanceTopUp())->forceFill(array_replace($ownerTopUpAttributes, ['status' => 'paid']));
    $paidOwnerTopUp->setRelation('customer', $ownerCustomer);
    $ownerTopUp->setRelation('customer', $ownerCustomer);
    $ownerRow = [
        'id' => 707,
        'customer_id' => 707,
        'chat_id' => '70000000001',
        'telegram_id' => '70000000001',
        'customer_locale' => 'id',
        'first_name' => 'P25 Synthetic Balance Buyer',
        'username' => 'p25_balance',
        'provider' => 'manual',
        'merchant_ref' => 'P25-BALANCE-SYNTHETIC',
        'currency' => 'IDR',
        'amount' => 10000,
        'status' => 'recovery_pending',
        'provider_total_payment' => 10500,
        'provider_payment_method' => 'QR_MANUAL',
        'paid_at' => $ownerPaidAt->toIso8601String(),
        'settled_at' => $ownerPaidAt->toIso8601String(),
        'provider_create_last_error' => $ownerTopUpAttributes['provider_create_last_error'],
    ];
    $fixtures['p25']['balance_owner'] = [
        'row' => $ownerRow,
        'owner' => $sourceWire('text', $ownerMessageMethod->invoke($ownerAlert, $ownerTopUp), []),
        'paid_row' => array_replace($ownerRow, ['status' => 'paid']),
        'paid' => $sourceWire('text', $paidOwnerMessageMethod->invoke($ownerAlert, $paidOwnerTopUp), []),
    ];

    $instructionRenderer = app(App\Services\Telegram\TelegramPaymentInstructionRenderer::class);
    $instructionExpiresAt = Carbon\CarbonImmutable::parse('2026-11-11 09:45:00', config('app.timezone'));
    $instructionTopUp = (new App\Models\StoreBalanceTopUp())->forceFill(['id' => 708]);
    $instructionPayable = new App\Services\StoreBalance\TopUp\StoreBalanceTopUpPayable(
        creditAmount: 10000,
        providerFee: 500,
        totalAmount: 10500,
        expiresAt: $instructionExpiresAt,
    );
    $manualQrContents = file_get_contents(public_path('qr-lhie.jpg'));
    if (! is_string($manualQrContents) || $manualQrContents === '') {
        throw new RuntimeException('Synthetic source fixture requires the existing manual QR asset.');
    }
    $instruction = $instructionRenderer->balanceTopUp(
        $instructionTopUp,
        $instructionPayable,
        $manualQrContents,
        'en',
        awaitingConfirmation: true,
    );
    $fixtures['p25']['balance_instructions'] = [
        'row' => [
            'id' => 708,
            'chat_id' => '1',
            'telegram_id' => '1',
            'customer_locale' => 'en',
            'provider' => 'manual',
            'amount' => 10000,
            'provider_fee' => 500,
            'provider_total_payment' => 10500,
            'provider_payment_method' => 'QR_MANUAL',
            'status' => 'awaiting_confirmation',
            'expires_at' => $instructionExpiresAt->addMinutes(15)->toIso8601String(),
            'provider_expires_at' => $instructionExpiresAt->toIso8601String(),
        ],
        'wire' => $sourceWire('caption', $instruction->caption(), $instruction->replyMarkup),
        'manual_qr_sha256' => hash('sha256', $manualQrContents),
    ];
    $proofCustomer = App\Models\Customer::findOrFail($fixtures['python']['customer_id']);
    $proofExpiresAt = now()->addMinutes(15)->startOfSecond();
    $proofReference = 'P25-PROOF-'.Illuminate\Support\Str::upper(Illuminate\Support\Str::random(12));
    $proofIntent = App\Models\StoreBalanceFundingIntent::query()->create([
        'customer_id' => $proofCustomer->getKey(),
        'telegram_navigation_root_id' => null,
        'chat_id' => (string) ($proofCustomer->telegram_id ?: '1'),
        'canonical_message_id' => 1,
        'policy_version' => 1,
        'amount' => 10000,
        'nonce' => Illuminate\Support\Str::random(32),
        'status' => App\Enums\StoreBalanceFundingIntentStatus::Confirmed,
        'expires_at' => $proofExpiresAt,
        'claimed_at' => now(),
    ]);
    $proofTopUp = App\Models\StoreBalanceTopUp::query()->create([
        'customer_id' => $proofCustomer->getKey(),
        'store_balance_funding_intent_id' => $proofIntent->getKey(),
        'telegram_navigation_root_id' => null,
        'chat_id' => (string) ($proofCustomer->telegram_id ?: '1'),
        'canonical_message_id' => 1,
        'locale' => 'id',
        'provider' => 'manual',
        'merchant_ref' => $proofReference,
        'idempotency_key' => 'p25-proof:'.$proofReference,
        'currency' => 'IDR',
        'amount' => 10000,
        'status' => App\Enums\StoreBalanceTopUpStatus::AwaitingConfirmation,
        'provider_fee' => 500,
        'provider_total_payment' => 10500,
        'provider_payment_method' => 'QR_MANUAL',
        'provider_create_state' => 'awaiting_confirmation',
        'provider_expires_at' => $proofExpiresAt,
        'expires_at' => $proofExpiresAt,
        'payment_proof_file_id' => 'P25-synthetic-payment-proof-file',
        'payment_proof_submitted_at' => now(),
        'payment_proof_admin_chat_id' => '1',
    ])->fresh(['customer']);
    $proofOutbound = app(App\Services\Telegram\TelegramOutboundEffectService::class);
    $proofEffect = $proofOutbound->recordBalanceTopUpPaymentProof($proofTopUp);
    $proofEligible = $proofEffect !== null && (string) $proofEffect->telegram_chat_id === '1';
    $proofRecipientChat = $proofEffect?->telegram_chat_id;
    $proofEffect?->delete();
    $proofPipeline = app(App\Services\Telegram\BalanceTopUpPaymentProofDeliveryPipeline::class);
    $proofLines = new ReflectionMethod($proofPipeline, 'lines');
    $proofLines->setAccessible(true);
    $proofMarkupMethod = new ReflectionMethod($proofPipeline, 'replyMarkup');
    $proofMarkupMethod->setAccessible(true);
    $proofLocale = App\Services\Telegram\TelegramLocale::DEFAULT_LOCALE;
    $proofRow = [
        'id' => $proofTopUp->getKey(),
        'customer_id' => $proofCustomer->getKey(),
        'chat_id' => '1',
        'telegram_id' => $proofCustomer->telegram_id,
        'locale' => 'id',
        'customer_locale' => (string) ($proofCustomer->locale ?: 'id'),
        'provider' => 'manual',
        'merchant_ref' => $proofReference,
        'currency' => 'IDR',
        'amount' => 10000,
        'provider_fee' => 500,
        'provider_total_payment' => 10500,
        'provider_payment_method' => 'QR_MANUAL',
        'status' => 'awaiting_confirmation',
        'expires_at' => $proofExpiresAt->toIso8601String(),
        'provider_expires_at' => $proofExpiresAt->toIso8601String(),
        'payment_proof_file_id' => 'P25-synthetic-payment-proof-file',
        'payment_proof_submitted_at' => $proofTopUp->payment_proof_submitted_at->toIso8601String(),
        'payment_proof_forwarded_at' => null,
        'payment_proof_admin_chat_id' => '1',
    ];
    $proofEligibilityCases = [];
    foreach ([
        'paid' => [['status' => App\Enums\StoreBalanceTopUpStatus::Paid], ['status' => 'paid']],
        'non_manual' => [['provider' => 'klikqris'], ['provider' => 'klikqris']],
        'missing_photo' => [['payment_proof_file_id' => null], ['payment_proof_file_id' => null]],
        'missing_submission_time' => [['payment_proof_submitted_at' => null], ['payment_proof_submitted_at' => null]],
        'already_forwarded' => [['payment_proof_forwarded_at' => now()], ['payment_proof_forwarded_at' => now()->toIso8601String()]],
    ] as $name => [$changes, $rowChanges]) {
        $caseTopUp = (clone $proofTopUp)->forceFill($changes);
        $caseEffect = $proofOutbound->recordBalanceTopUpPaymentProof($caseTopUp);
        $proofEligibilityCases[$name] = [
            'row' => array_replace($proofRow, $rowChanges),
            'eligible' => $caseEffect !== null,
        ];
        $caseEffect?->delete();
    }
    $fixtures['p25']['balance_proof'] = [
        'row' => $proofRow,
        'eligible' => $proofEligible,
        'recipient_chat_id' => (string) $proofRecipientChat,
        'eligibility_cases' => $proofEligibilityCases,
        'photo_file_id' => $proofTopUp->payment_proof_file_id,
        'wire' => $sourceWire(
            'caption',
            implode("\n", $proofLines->invoke($proofPipeline, $proofTopUp, $proofLocale)),
            $proofMarkupMethod->invoke($proofPipeline, $proofTopUp, $proofLocale),
            '1',
            null,
        ),
    ];

    // Presentation-only paid snapshot; this fixture creates no provider event or balance credit.
    $receiptCustomer = App\Models\Customer::factory()->create([
        'telegram_id' => 1,
        'first_name' => 'P25 Synthetic Receipt Buyer',
        'locale' => 'id',
    ]);
    $receiptBot = new class extends App\Services\Telegram\TelegramBotClient {
        public array $sent = [];

        public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $options = []): array
        {
            $this->sent[] = [
                'chat_id' => (string) $chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
                'options' => $options,
            ];

            return ['ok' => true, 'result' => ['message_id' => 707700 + count($this->sent)]];
        }
    };
    app()->instance(App\Services\Telegram\TelegramBotClient::class, $receiptBot);
    $receiptService = app(App\Services\Telegram\TelegramOutboundEffectService::class);
    $receiptDelivery = app(App\Services\Telegram\BalanceTopUpReceiptDeliveryPipeline::class);
    $receiptFixture = [];
    $receiptIntentIds = [];
    foreach (['id', 'en', 'ms'] as $receiptLocale) {
        $receiptCustomer->update(['locale' => $receiptLocale]);
        $receiptPaidAt = now()->subMinutes(5)->startOfSecond();
        $receiptExpiresAt = now()->addHour()->startOfSecond();
        $receiptReference = 'P25-RECEIPT-'.$receiptLocale.'-'.Illuminate\Support\Str::upper(Illuminate\Support\Str::random(8));
        $receiptIntent = App\Models\StoreBalanceFundingIntent::query()->create([
            'customer_id' => $receiptCustomer->getKey(),
            'telegram_navigation_root_id' => null,
            'chat_id' => '1',
            'canonical_message_id' => 1,
            'policy_version' => 1,
            'amount' => 10000,
            'nonce' => Illuminate\Support\Str::random(32),
            'status' => App\Enums\StoreBalanceFundingIntentStatus::Confirmed,
            'expires_at' => $receiptExpiresAt,
            'claimed_at' => now(),
        ]);
        $receiptIntentIds[] = $receiptIntent->getKey();
        $receiptTopUp = App\Models\StoreBalanceTopUp::query()->create([
            'customer_id' => $receiptCustomer->getKey(),
            'store_balance_funding_intent_id' => $receiptIntent->getKey(),
            'telegram_navigation_root_id' => null,
            'chat_id' => '1',
            'canonical_message_id' => 1,
            'locale' => $receiptLocale,
            'provider' => 'manual',
            'merchant_ref' => $receiptReference,
            'provider_reference' => 'REF<&',
            'idempotency_key' => 'p25-receipt:'.$receiptReference,
            'currency' => 'IDR',
            'amount' => 10000,
            'status' => App\Enums\StoreBalanceTopUpStatus::Paid,
            'provider_fee' => 500,
            'provider_total_payment' => 10500,
            'provider_payment_method' => 'QR_MANUAL',
            'provider_create_state' => 'paid',
            'expires_at' => $receiptExpiresAt,
            'provider_expires_at' => $receiptExpiresAt,
            'paid_at' => $receiptPaidAt,
            'settled_at' => $receiptPaidAt,
        ])->fresh(['customer']);
        $receiptEffect = $receiptService->recordBalanceTopUpReceipt($receiptTopUp);
        if ($receiptEffect === null) {
            throw new RuntimeException('Synthetic source receipt fixture was not eligible.');
        }
        $receiptDelivery->deliver((string) $receiptTopUp->getKey(), (string) $receiptEffect->getKey());
        $sent = $receiptBot->sent[array_key_last($receiptBot->sent)] ?? null;
        $receiptState = $receiptEffect->fresh()->state->value;
        if (! is_array($sent) || $receiptState !== 'sent') {
            throw new RuntimeException('Synthetic source receipt did not reach the sent outcome.');
        }
        $receiptRow = [
            'id' => $receiptTopUp->getKey(),
            'customer_id' => $receiptCustomer->getKey(),
            'chat_id' => '1',
            'telegram_id' => $receiptCustomer->telegram_id,
            'locale' => $receiptLocale,
            'customer_locale' => $receiptLocale,
            'provider' => 'manual',
            'merchant_ref' => $receiptReference,
            'provider_reference' => 'REF<&',
            'currency' => 'IDR',
            'amount' => 10000,
            'provider_total_payment' => 10500,
            'provider_payment_method' => 'QR_MANUAL',
            'status' => 'paid',
            'paid_at' => $receiptPaidAt->toIso8601String(),
            'settled_at' => $receiptPaidAt->toIso8601String(),
        ];
        $receiptFixture[$receiptLocale] = [
            'row' => $receiptRow,
            'recipient_chat_id' => '1',
            'delivery_outcome' => $receiptState,
            'wire' => $sourceWire('text', $sent['text'], $sent['reply_markup'], '1'),
        ];
        $receiptEffect->delete();
        $receiptTopUp->delete();
    }
    App\Models\StoreBalanceFundingIntent::query()->whereKey($receiptIntentIds)->delete();
    $receiptCustomer->delete();
    $fixtures['p25']['balance_receipt'] = ['by_locale' => $receiptFixture];

    $waitlistConfirmationContext = [
        'app_name' => config('app.name'),
        'product_name' => 'P25 Confirmation <Product> & variant',
        'confirmation_url' => 'http://127.0.0.1:18801/waitlist/confirm/42?token=synthetic-p25',
    ];
    $waitlistConfirmation = (new App\Mail\ProductWaitlistConfirmationMail(
        $waitlistConfirmationContext['product_name'],
        $waitlistConfirmationContext['confirmation_url'],
    ))->build();
    $fixtures['p25']['waitlist_confirmation_context'] = $waitlistConfirmationContext;
    $fixtures['p25']['waitlist_confirmation_mail'] = [
        'subject' => $waitlistConfirmation->subject,
        'text' => view($waitlistConfirmation->textView, $waitlistConfirmation->buildViewData())->render(),
        'html' => $waitlistConfirmation->render(),
    ];

    $guestContext = [
        'event_type' => App\Enums\GuestOrderNotificationEvent::PaymentConfirmed->value,
        'invoice_number' => 'INV-P25-GUEST',
        'order_status' => 'processing',
        'status_label' => 'Sedang diproses',
        'items' => [['name' => 'Synthetic <Product> & edition', 'quantity' => 2]],
        'payment_deadline' => '11 November 2026, 13:00 WIB',
        'invoice_url' => 'http://127.0.0.1:18801/shop/orders/p25?expires=synthetic&signature=fixture',
        'order_access_url' => 'http://127.0.0.1:18801/akses-pesanan',
    ];
    $guestMail = (new App\Mail\GuestOrderMilestoneMail(
        App\Enums\GuestOrderNotificationEvent::PaymentConfirmed,
        $guestContext['invoice_number'],
        $guestContext['order_status'],
        $guestContext['status_label'],
        $guestContext['items'],
        $guestContext['payment_deadline'],
        $guestContext['invoice_url'],
        $guestContext['order_access_url'],
    ))->build();
    $fixtures['p25']['guest_mail_context'] = $guestContext;
    $fixtures['p25']['guest_mail'] = [
        'subject' => $guestMail->subject,
        'text' => view($guestMail->textView, $guestMail->buildViewData())->render(),
        'html' => $guestMail->render(),
    ];

    $credentialInput = [
        'invoice_number' => 'INV-P25-CREDENTIAL',
        'invoice_url' => 'http://127.0.0.1:18801/shop/orders/p25-credential?expires=synthetic&signature=fixture',
        'groups' => [[
            'product_name' => 'Synthetic <Premium> Product & edition',
            'default_tutorial' => 'Keep the credential private and use a personal device.',
            'guide' => [
                'title' => 'Safe use guide',
                'url' => 'https://p25.example.invalid/guide?mode=private&source=fixture',
            ],
            'accounts' => [[
                'number' => 1,
                'format' => 'structured',
                'email' => 'synthetic-user@example.invalid',
                'password' => 'P25-synthetic-only-password',
                'access_method' => 'auth_token',
                'access_value' => 'P25-synthetic-only-access-token',
                'reader_mail_link' => null,
                'tutorial' => 'Open the product in a private browser.',
            ]],
        ]],
    ];
    $credentialGroups = [[
        'product_name' => $credentialInput['groups'][0]['product_name'],
        'accounts' => [[
            'label' => 'Data akun 1',
            'kind' => 'structured',
            'email' => $credentialInput['groups'][0]['accounts'][0]['email'],
            'password' => $credentialInput['groups'][0]['accounts'][0]['password'],
            'access' => [
                'label' => 'Auth Token',
                'value' => $credentialInput['groups'][0]['accounts'][0]['access_value'],
                'url' => null,
                'domain' => null,
            ],
            'tutorial' => $credentialInput['groups'][0]['accounts'][0]['tutorial'],
        ]],
        'delivery_note' => $credentialInput['groups'][0]['default_tutorial'],
        'usage_guide' => $credentialInput['groups'][0]['guide'],
    ]];
    $defaultInstruction = (string) config('storefront.fulfillment.automatic_default_instruction');
    $credentialMail = (new App\Mail\OrderCredentialMail(
        $credentialInput['invoice_number'],
        $credentialGroups,
        [$credentialInput['groups'][0]['guide']],
        $credentialInput['invoice_url'],
        'http://127.0.0.1:18801/akses-pesanan',
        $defaultInstruction,
    ))->build();
    $fixtures['p25']['credential_mail_input'] = $credentialInput;
    $fixtures['p25']['credential_mail'] = [
        'subject' => $credentialMail->subject,
        'text' => view($credentialMail->textView, $credentialMail->buildViewData())->render(),
        'html' => $credentialMail->render(),
        'default_instruction' => $defaultInstruction,
    ];

    $authMailFields = static function (Illuminate\Notifications\Messages\MailMessage $message): array {
        return [
            'subject' => $message->subject,
            'greeting' => $message->greeting,
            'intro_lines' => $message->introLines,
            'action_text' => $message->actionText,
            'action_url' => $message->actionUrl,
            'outro_lines' => $message->outroLines,
        ];
    };
    $authMail = [];
    foreach ([
        'email_verification' => [App\Enums\CustomerAuthTokenPurpose::EmailVerification, str_repeat('a', 64)],
        'email_otp' => [App\Enums\CustomerAuthTokenPurpose::EmailOtp, '804216'],
        'password_reset' => [App\Enums\CustomerAuthTokenPurpose::PasswordReset, str_repeat('c', 64)],
    ] as $purpose => [$authPurpose, $token]) {
        $authMail[$purpose] = $authMailFields(
            (new App\Notifications\CustomerAuthMailNotification($authPurpose, $token))->toMail(new stdClass()),
        );
    }
    $authMail['magic'] = $authMailFields(
        (new App\Notifications\CustomerLoginLinkNotification(
            route('customer.login-link.show').'#token='.str_repeat('b', 64),
        ))->toMail(new stdClass()),
    );
    $fixtures['p25']['auth_mail'] = $authMail;

    $broadcastMessage = '<b>Admin-authored P25</b> &amp; Japanese copy';
    $broadcastInput = [
        'message' => $broadcastMessage,
        'photo_file_id' => 'p25-synthetic-photo-reference',
        'reply_markup' => [
            'id' => ['inline_keyboard' => [[['text' => 'Katalog', 'callback_data' => 'catalog']]]],
            'en' => ['inline_keyboard' => [[['text' => 'Catalog', 'callback_data' => 'catalog']]]],
            'ms' => ['inline_keyboard' => [[['text' => 'Katalog', 'callback_data' => 'catalog']]]],
        ],
    ];
    $broadcastPipeline = app(App\Services\Telegram\AdminBroadcastDeliveryPipeline::class);
    $broadcastTextMethod = new ReflectionMethod($broadcastPipeline, 'adminBroadcastText');
    $broadcastTextMethod->setAccessible(true);
    $broadcastMarkupMethod = new ReflectionMethod($broadcastPipeline, 'adminBroadcastReplyMarkup');
    $broadcastMarkupMethod->setAccessible(true);
    $broadcastByLocale = [];
    foreach (['id', 'en', 'ms'] as $locale) {
        $markup = $broadcastMarkupMethod->invoke($broadcastPipeline, $broadcastInput['reply_markup'], $locale);
        $broadcastByLocale[$locale] = [
            'message' => $sourceWire('text', $broadcastTextMethod->invoke($broadcastPipeline, $broadcastMessage, $locale), $markup, '1', null),
            'photo' => $sourceWire('caption', $broadcastMessage, $markup, '1', null),
        ];
    }
    $fixtures['p25']['broadcast'] = ['input' => $broadcastInput, 'by_locale' => $broadcastByLocale];
    $purchaseProduct = product('p25-broadcast-button', 1, 1000);
    $purchaseProduct->forceFill([
        'name' => 'P25 Broadcast button '.str_repeat('x', 45),
        'icon' => '📦',
        'custom_emoji_id' => '987654322',
    ])->save();
    $capturedBroadcast = new class extends App\Services\Telegram\TelegramBroadcastService {
        public array $payload = [];

        public function __construct() {}

        public function queueAdminBroadcast(
            string $message,
            array $replyMarkup = [],
            ?string $idempotencyKey = null,
            ?string $photoFileId = null,
        ): array {
            $this->payload = compact('message', 'replyMarkup', 'idempotencyKey', 'photoFileId');

            return ['broadcast_id' => 707, 'skipped' => false];
        }
    };
    $broadcastDrafts = new class((int) $purchaseProduct->id) extends App\Services\Telegram\TelegramBroadcastDrafts {
        public function __construct(private readonly int $productId) {}

        public function mutate(App\Models\Customer $customer, string $draftId, \Closure $mutation): array
        {
            $operation = $mutation([
                'message' => '<b>Admin-authored product button</b>',
                'photo_file_id' => 'p25-purchase-photo-reference',
                'product_id' => $this->productId,
                'draft_id' => 'P25Broadcast707X',
            ]);

            return ['locked' => true, 'matched' => true, 'value' => $operation['value'] ?? null];
        }
    };
    $broadcastBot = new class extends App\Services\Telegram\TelegramBotClient {
        public function __construct() {}

        public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void {}

        public function sendMessage(int|string $chatId, string $text, array $replyMarkup = [], array $options = []): array
        {
            return [];
        }
    };
    $broadcastIntents = new App\Services\Telegram\TelegramBroadcastIntents(
        $broadcastBot,
        $capturedBroadcast,
        $broadcastDrafts,
        app(App\Services\Telegram\TelegramAdminAccess::class),
        app(App\Services\Telegram\TelegramUiIcons::class),
        app(App\Services\Telegram\TelegramProductNameRenderer::class),
        app(App\Services\Catalog\CatalogFamilyIconResolver::class),
    );
    $broadcastCustomer = (new App\Models\Customer())->forceFill(['id' => 1, 'telegram_id' => '1']);
    $sendBroadcast = new ReflectionMethod($broadcastIntents, 'send');
    $sendBroadcast->setAccessible(true);
    $sendBroadcast->invoke($broadcastIntents, $broadcastCustomer, '1', 'p25-callback', 'P25Broadcast707X');
    $purchaseButtonByLocale = [];
    $purchaseButtonWireByLocale = [];
    foreach (['id', 'en', 'ms'] as $locale) {
        $purchaseButtonWireByLocale[$locale] = $sourceWire(
            'text',
            'Synthetic Broadcast product button',
            $capturedBroadcast->payload['replyMarkup'][$locale],
        );
        $purchaseButtonByLocale[$locale] = $purchaseButtonWireByLocale[$locale]['reply_markup'];
    }
    $fixtures['p25']['broadcast_purchase_button'] = [
        'product_id' => (int) $purchaseProduct->id,
        'name' => $purchaseProduct->name,
        'by_locale' => $purchaseButtonByLocale,
        'wire_by_locale' => $purchaseButtonWireByLocale,
    ];
    $broadcastContent = new ReflectionMethod($broadcastIntents, 'broadcastContent');
    $broadcastContent->setAccessible(true);
    $photoOnlyContent = $broadcastContent->invoke($broadcastIntents, '', [
        'caption' => '',
        'photo' => [
            ['file_id' => 'p25-small-photo-reference', 'width' => 24, 'height' => 24],
            ['file_id' => 'p25-largest-photo-reference', 'width' => 640, 'height' => 480],
        ],
    ]);
    $fixtures['p25']['broadcast_photo_only'] = [
        'content' => $photoOnlyContent,
        'wire' => $sourceWire('caption', $photoOnlyContent['message'], [], '1', null),
    ];

    $productAttributes = [
        'id' => 42,
        'name' => 'P25 synthetic <product> & edition',
        'icon' => '📦',
        'custom_emoji_id' => '987654322',
        'description' => 'P25 synthetic description',
        'description_en' => 'P25 synthetic description',
        'description_ms' => 'P25 synthetic description',
        'price_amount' => 20000,
        'currency' => 'IDR',
        'usdt_price_amount' => 2000001,
        'stock' => 3,
        'reserved_stock' => 0,
        'is_active' => true,
    ];
    $product = (new App\Models\Product())->forceFill($productAttributes);
    $catalogIcon = app(App\Services\Catalog\CatalogFamilyIconResolver::class)->forProduct($product);
    $syntheticCustomer = (new App\Models\Customer())->forceFill([
        'id' => 1,
        'telegram_id' => '1',
        'locale' => 'en',
    ]);
    $stockService = app(App\Services\Telegram\TelegramProductNotificationService::class);
    $customerStockMethod = new ReflectionMethod($stockService, 'renderCustomerDeliveries');
    $customerStockMethod->setAccessible(true);
    $stockCustomerByLocale = [];
    foreach (['id', 'en', 'ms'] as $locale) {
        $localizedCustomer = clone $syntheticCustomer;
        $localizedCustomer->locale = $locale;
        $delivery = $customerStockMethod->invoke(
            $stockService, $product, $catalogIcon, 3, false, [$localizedCustomer], null,
        )[0];
        $stockCustomerByLocale[$locale] = $sourceWire('text', $delivery['text'], $delivery['reply_markup']);
    }
    $channelStockMessageMethod = new ReflectionMethod($stockService, 'storeChannelRestockMessage');
    $channelStockMessageMethod->setAccessible(true);
    $channelStockMarkupMethod = new ReflectionMethod($stockService, 'storeChannelProductReplyMarkup');
    $channelStockMarkupMethod->setAccessible(true);
    $stockMarkup = $channelStockMarkupMethod->invoke($stockService, $product);
    $fixtures['p25']['stock'] = [
        'product' => $productAttributes,
        'movement' => ['type' => 'adjustment', 'quantity' => 3, 'available_before' => 0, 'available_after' => 3],
        'event' => ['promoted_tier_minimum' => null],
        'customer_by_locale' => $stockCustomerByLocale,
        'customer' => $stockCustomerByLocale['en'],
        'channel' => $sourceWire(
            'text',
            $channelStockMessageMethod->invoke($stockService, $product, $catalogIcon, 3, 3, null),
            $stockMarkup,
        ),
    ];

    $priceEvent = (new App\Models\ProductPriceChangeEvent())->forceFill([
        'id' => 42,
        'product_id' => 42,
        'product_name' => $productAttributes['name'],
        'product_icon' => $productAttributes['icon'],
        'product_custom_emoji_id' => $productAttributes['custom_emoji_id'],
        'old_price_amount' => 25000,
        'new_price_amount' => 20000,
        'old_usdt_price_amount' => 3000001,
        'new_usdt_price_amount' => 2000001,
    ]);
    $priceService = app(App\Services\Telegram\ProductPriceChangeNotificationService::class);
    $priceMessageMethod = new ReflectionMethod($priceService, 'message');
    $priceMessageMethod->setAccessible(true);
    $priceMarkupMethod = new ReflectionMethod($priceService, 'replyMarkup');
    $priceMarkupMethod->setAccessible(true);
    $priceChannelMarkupMethod = new ReflectionMethod($priceService, 'storeChannelReplyMarkup');
    $priceChannelMarkupMethod->setAccessible(true);
    $priceCustomerByLocale = [];
    foreach (['id', 'en', 'ms'] as $locale) {
        $priceCustomerByLocale[$locale] = $sourceWire(
            'text',
            $priceMessageMethod->invoke($priceService, $priceEvent, $locale),
            $priceMarkupMethod->invoke($priceService, $priceEvent, $locale),
        );
    }
    $priceChannelText = $priceMessageMethod->invoke(
        $priceService,
        $priceEvent,
        'en',
        App\Services\Telegram\CustomEmojiCapability::SCOPE_CHANNEL,
    );
    $priceChannelMarkup = $priceChannelMarkupMethod->invoke($priceService, $priceEvent);
    $fixtures['p25']['price'] = [
        'event' => [
            'product_id' => 42,
            'product_name' => $productAttributes['name'],
            'product_icon' => $productAttributes['icon'],
            'product_custom_emoji_id' => $productAttributes['custom_emoji_id'],
            'old_price_amount' => 25000,
            'new_price_amount' => 20000,
            'old_usdt_price_amount' => 3000001,
            'new_usdt_price_amount' => 2000001,
            'product_url' => $priceChannelMarkup['inline_keyboard'][0][0]['url'],
        ],
        'customer_by_locale' => $priceCustomerByLocale,
        'customer' => $priceCustomerByLocale['en'],
        'channel' => $sourceWire('text', $priceChannelText, $priceChannelMarkup),
    ];

    $proofSnapshot = [
        'invoice_number' => 'INV-P25-PROOF',
        'buyer_name' => 'Pri***',
        'paid_at' => '2026-11-11T12:34:00+07:00',
        'payment_method' => 'QRIS · API',
        'currency' => 'IDR',
        'item_currency' => 'IDR',
        'items' => [[
            'product_name' => 'P25 synthetic <product> & edition',
            'product_icon' => '📦',
            'product_custom_emoji_id' => '987654322',
            'quantity' => 2,
            'quantity_price_tier_minimum' => 2,
            'unit_price' => 5000,
            'subtotal' => 10000,
        ]],
        'provider_fee' => 100,
        'payment_subtotal' => 10000,
        'total_paid' => 10100,
        'origin' => 'api',
    ];
    $proofPresentation = new App\Services\PaidInvoice\PaidInvoicePresentation(
        invoiceId: $proofSnapshot['invoice_number'],
        buyerIdentifier: '700*****01',
        paidAt: Carbon\CarbonImmutable::parse($proofSnapshot['paid_at']),
        paymentMethod: $proofSnapshot['payment_method'],
        currency: $proofSnapshot['currency'],
        items: $proofSnapshot['items'],
        providerFee: $proofSnapshot['provider_fee'],
        totalPaid: $proofSnapshot['total_paid'],
        buyerName: $proofSnapshot['buyer_name'],
        itemCurrency: $proofSnapshot['item_currency'],
        paymentSubtotal: $proofSnapshot['payment_subtotal'],
        origin: $proofSnapshot['origin'],
    );
    $proofFormatter = app(App\Services\Telegram\SalesProofPostFormatter::class);
    $proofCaption = $proofFormatter->format($proofPresentation, 17, 3);
    $proofUrl = app(App\Storefront\StorefrontUrl::class)->telegramProductStartUrlForId(42);
    $proofMarkup = ['inline_keyboard' => [[[
        'text' => $proofFormatter->shoppingButtonLabel(),
        'url' => $proofUrl,
    ]]]];
    $proofWire = $sourceWire('caption', $proofCaption, $proofMarkup);
    $proofImage = app(App\Services\PaidInvoice\PaidInvoiceRenderer::class)->render($proofPresentation);
    $proofImageDimensions = getimagesizefromstring($proofImage->bytes);
    $fixtures['p25']['sales_proof'] = [
        'snapshot' => $proofSnapshot,
        'number' => 17,
        'remaining_stock' => 3,
        'caption' => $proofWire['caption'],
        'wire' => $proofWire,
        'reply_markup' => $proofWire['reply_markup'],
        'photo_base64' => base64_encode($proofImage->bytes),
        'photo_dimensions' => [$proofImageDimensions[0], $proofImageDimensions[1]],
        'shopping_url' => $proofUrl,
    ];

    $sourceOrder = App\Models\Order::query()
        ->with(['customer', 'orderItems', 'payments'])
        ->findOrFail($fixtures['php']['order_id']);
    $sourceItem = $sourceOrder->orderItems->sole();
    $allocated = Illuminate\Support\Facades\DB::table('product_stock_items')
        ->where('order_item_id', $sourceItem->getKey())
        ->whereNull('retired_at')
        ->orderBy('id')
        ->get(['id']);
    foreach ($allocated->slice(max(0, (int) $sourceItem->quantity)) as $extra) {
        Illuminate\Support\Facades\DB::table('product_stock_items')->where('id', $extra->id)->update(['retired_at' => now()]);
    }
    for ($index = $allocated->count(); $index < (int) $sourceItem->quantity; $index++) {
        Illuminate\Support\Facades\DB::table('product_stock_items')->insert([
            'product_id' => $sourceItem->product_id,
            'order_item_id' => $sourceItem->getKey(),
            'secret' => 'P25-synthetic-credential-'.$sourceItem->getKey().'-'.$index,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
    $apiClaimId = Illuminate\Support\Facades\DB::table('customer_api_order_claims')->insertGetId([
        'customer_id' => $sourceOrder->customer_id,
        'operation' => 'orders.create',
        'idempotency_key' => 'p25-api-sales-proof-'.$sourceOrder->getKey(),
        'fingerprint' => hash('sha256', 'p25-api-sales-proof-'.$sourceOrder->getKey()),
        'credential_id' => null,
        'state' => 'accepted',
        'attempt_token' => null,
        'lease_expires_at' => null,
        'preference' => 'idr_first',
        'preference_source' => 'account',
        'product_id' => $sourceItem->product_id,
        'quantity' => $sourceItem->quantity,
        'expected_idr_total' => $sourceOrder->total_amount,
        'expected_usd_total_micro' => null,
        'order_id' => $sourceOrder->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $sourceOrder->forceFill(['api_order_claim_id' => $apiClaimId])->save();
    $sourceOrder = $sourceOrder->fresh()->load(['customer', 'orderItems', 'payments']);
    $salesProofEligibility = app(App\Services\Telegram\SalesProofEligibility::class);
    $sourceProofReason = $salesProofEligibility->rejectionReason($sourceOrder);
    $sourceProof = app(App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter::class)->present($sourceOrder);
    $apiGates = [];

    $paidPayment = $sourceOrder->payments->first();
    $reviewId = Illuminate\Support\Facades\DB::table('manual_fulfillment_reviews')->insertGetId([
        'order_id' => $sourceOrder->getKey(),
        'payment_id' => $paidPayment->getKey(),
        'amount' => $paidPayment->amount,
        'currency' => $paidPayment->currency,
        'reason' => 'late_payment',
        'state' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $apiGates['open_review_rejected'] = $salesProofEligibility->rejectionReason(
        $sourceOrder->fresh()->load(['customer', 'orderItems', 'payments']),
    ) !== null;
    Illuminate\Support\Facades\DB::table('manual_fulfillment_reviews')->where('id', $reviewId)->delete();

    $stockItemId = Illuminate\Support\Facades\DB::table('product_stock_items')
        ->where('order_item_id', $sourceItem->getKey())
        ->whereNull('retired_at')
        ->orderBy('id')
        ->value('id');
    Illuminate\Support\Facades\DB::table('product_stock_items')->where('id', $stockItemId)->update(['retired_at' => now()]);
    $apiGates['allocation_mismatch_rejected'] = $salesProofEligibility->rejectionReason(
        $sourceOrder->fresh()->load(['customer', 'orderItems', 'payments']),
    ) !== null;
    Illuminate\Support\Facades\DB::table('product_stock_items')->where('id', $stockItemId)->update(['retired_at' => null]);

    $pendingPaymentId = (string) Illuminate\Support\Str::uuid();
    $pendingPaymentAt = now()->addMinute();
    Illuminate\Support\Facades\DB::table('payments')->insert([
        'id' => $pendingPaymentId,
        'order_id' => $sourceOrder->getKey(),
        'provider' => 'manual',
        'merchant_ref' => 'P25-API-PENDING-'.$pendingPaymentId,
        'method' => 'QR_MANUAL',
        'amount' => $sourceOrder->total_amount,
        'currency' => $sourceOrder->currency,
        'status' => 'pending',
        'paid_at' => null,
        'created_at' => $pendingPaymentAt,
        'updated_at' => $pendingPaymentAt,
    ]);
    $apiGates['latest_unpaid_payment_rejected'] = $salesProofEligibility->rejectionReason(
        $sourceOrder->fresh()->load(['customer', 'orderItems', 'payments']),
    ) !== null;
    Illuminate\Support\Facades\DB::table('payments')->where('id', $pendingPaymentId)->delete();

    $nonApiOrderId = (string) Illuminate\Support\Str::uuid();
    $nonApiInvoiceNumber = 'P25-NON-API-'.$nonApiOrderId;
    $nonApiReleaseAt = now();
    $nonApiPaidAt = $nonApiReleaseAt->copy()->addMinutes(2);
    $nonApiSaleAt = $nonApiPaidAt->copy()->addSecond();
    $nonApiPendingAt = $nonApiPaidAt->copy()->addMinute();
    $nonApiOrderRow = (array) Illuminate\Support\Facades\DB::table('orders')
        ->where('id', $sourceOrder->getKey())->first();
    $nonApiOrderRow['id'] = $nonApiOrderId;
    $nonApiOrderRow['invoice_number'] = $nonApiInvoiceNumber;
    $nonApiOrderRow['cart_id'] = null;
    $nonApiOrderRow['checkout_key'] = null;
    $nonApiOrderRow['api_order_claim_id'] = null;
    foreach (['credential_access_key_hash', 'notification_email', 'support_contact_channel',
              'support_contact_value', 'balance_checkout_snapshot'] as $privateColumn) {
        if (array_key_exists($privateColumn, $nonApiOrderRow)) {
            $nonApiOrderRow[$privateColumn] = null;
        }
    }
    Illuminate\Support\Facades\DB::table('orders')->insert($nonApiOrderRow);

    $nonApiItemRow = (array) Illuminate\Support\Facades\DB::table('order_items')
        ->where('id', $sourceItem->getKey())->first();
    unset($nonApiItemRow['id']);
    $nonApiItemRow['order_id'] = $nonApiOrderId;
    $nonApiItemRow['created_at'] = now();
    $nonApiItemRow['updated_at'] = now();
    Illuminate\Support\Facades\DB::table('order_items')->insert($nonApiItemRow);

    $nonApiPaymentRow = (array) Illuminate\Support\Facades\DB::table('payments')
        ->where('id', $paidPayment->getKey())->first();
    $nonApiPaymentId = (string) Illuminate\Support\Str::uuid();
    $nonApiPaymentRow['id'] = $nonApiPaymentId;
    $nonApiPaymentRow['order_id'] = $nonApiOrderId;
    $nonApiPaymentRow['merchant_ref'] = 'P25-NON-API-PAID-'.$nonApiPaymentId;
    $nonApiPaymentRow['provider_reference'] = null;
    $nonApiPaymentRow['status'] = 'paid';
    $nonApiPaymentRow['paid_at'] = $nonApiPaidAt;
    $nonApiPaymentRow['created_at'] = $nonApiPaidAt;
    $nonApiPaymentRow['updated_at'] = $nonApiPaidAt;
    Illuminate\Support\Facades\DB::table('payments')->insert($nonApiPaymentRow);

    $nonApiPendingPaymentId = (string) Illuminate\Support\Str::uuid();
    Illuminate\Support\Facades\DB::table('payments')->insert([
        'id' => $nonApiPendingPaymentId,
        'order_id' => $nonApiOrderId,
        'provider' => 'manual',
        'merchant_ref' => 'P25-NON-API-PENDING-'.$nonApiPendingPaymentId,
        'method' => 'QR_MANUAL',
        'amount' => $sourceOrder->total_amount,
        'currency' => $sourceOrder->currency,
        'status' => 'pending',
        'paid_at' => null,
        'created_at' => $nonApiPendingAt,
        'updated_at' => $nonApiPendingAt,
    ]);
    $nonApiSaleMovementId = Illuminate\Support\Facades\DB::table('inventory_movements')->insertGetId([
        'product_id' => $sourceItem->product_id,
        'order_id' => $nonApiOrderId,
        'type' => 'sale',
        'quantity' => -$sourceItem->quantity,
        'notes' => 'P25 synthetic late-payment sale proof',
        'created_at' => $nonApiSaleAt,
    ]);
    $releaseMovementId = Illuminate\Support\Facades\DB::table('inventory_movements')->insertGetId([
        'product_id' => $sourceItem->product_id,
        'order_id' => $nonApiOrderId,
        'type' => 'release',
        'quantity' => $sourceItem->quantity,
        'notes' => 'P25 synthetic late-payment reservation release',
        'created_at' => $nonApiReleaseAt,
    ]);
    $nonApiOrder = App\Models\Order::query()->with(['customer', 'orderItems', 'payments'])
        ->findOrFail($nonApiOrderId);
    $nonApiReason = $salesProofEligibility->rejectionReason($nonApiOrder);
    $nonApiProof = app(App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter::class)
        ->present($nonApiOrder);
    $nonApiAfterRelease = [
        'order_id' => $nonApiOrderId,
        'eligible' => $nonApiReason === null,
        'api' => $nonApiOrder->api_order_claim_id !== null,
        'release_movement' => Illuminate\Support\Facades\DB::table('inventory_movements')
            ->where('id', $releaseMovementId)->exists(),
        'sale_movement' => Illuminate\Support\Facades\DB::table('inventory_movements')
            ->where('id', $nonApiSaleMovementId)->exists(),
        'release_before_paid_payment' => $nonApiReleaseAt->lessThan($nonApiPaidAt),
        'paid_payment_before_sale' => $nonApiPaidAt->lessThan($nonApiSaleAt),
        'latest_unpaid_payment_present' => $nonApiOrder->payments
            ->contains(fn ($payment): bool => $payment->status === App\Enums\PaymentStatus::Pending
                && $payment->created_at->greaterThan($nonApiPaidAt)),
        'invoice_number' => $nonApiProof->invoiceId,
        'buyer_name' => $nonApiProof->buyerName,
        'paid_at' => $nonApiProof->paidAt->toIso8601String(),
        'payment_method' => $nonApiProof->paymentMethod,
        'currency' => $nonApiProof->currency,
        'item_currency' => $nonApiProof->itemCurrency,
        'items' => $nonApiProof->items,
        'provider_fee' => $nonApiProof->providerFee,
        'payment_subtotal' => $nonApiProof->paymentSubtotal,
        'total_paid' => $nonApiProof->totalPaid,
        'origin' => $nonApiProof->origin,
    ];

    $fixtures['p25']['source_order'] = [
        'order_id' => (string) $sourceOrder->getKey(),
        'eligible' => $sourceProofReason === null,
        'rejection_reason' => $sourceProofReason,
        'api_gates' => $apiGates,
        'invoice_number' => $sourceProof->invoiceId,
        'buyer_identifier' => $sourceProof->buyerIdentifier,
        'buyer_name' => $sourceProof->buyerName,
        'paid_at' => $sourceProof->paidAt->toIso8601String(),
        'payment_method' => $sourceProof->paymentMethod,
        'currency' => $sourceProof->currency,
        'item_currency' => $sourceProof->itemCurrency,
        'items' => $sourceProof->items,
        'provider_fee' => $sourceProof->providerFee,
        'payment_subtotal' => $sourceProof->paymentSubtotal,
        'total_paid' => $sourceProof->totalPaid,
        'origin' => $sourceProof->origin,
        'non_api_after_release' => $nonApiAfterRelease,
    ];

    $fixtures['p25']['browser_username'] = 'SyntheticTestBot';
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
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
