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
echo json_encode($fixtures, JSON_THROW_ON_ERROR);
