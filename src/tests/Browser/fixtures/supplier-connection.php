<?php

use App\Enums\UserRole;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Customer;
use App\Models\ManualFulfillmentReview;
use App\Models\Product;
use App\Models\ProductSupplierSource;
use App\Models\Supplier;
use App\Models\SupplierCatalogItem;
use App\Models\SupplierCurrencyRate;
use App\Models\SupplierPricePolicy;
use App\Models\User;
use App\Services\Checkout\CheckoutPaymentIntents;
use App\Services\OrderCheckoutService;
use App\Services\OrderLifecycle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || config('database.connections.sqlite.database') !== getenv('DB_DATABASE')
    || ! str_starts_with(str_replace('\\', '/', config('database.connections.sqlite.database')), str_replace('\\', '/', sys_get_temp_dir()).'/supplier-connection-browser-')) {
    throw new RuntimeException('Supplier connection browser fixtures require a disposable SQLite test database.');
}
if (($argv[1] ?? null) === 'prepare') {
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Disposable supplier connection migrations failed.');
    }
    echo '{"prepared":true}';
    exit;
}
if (($argv[1] ?? null) === 'unlimited-review') {
    $product = Product::factory()->create(['name' => 'Unlimited Browser', 'manual_unlimited' => true, 'stock' => 0]);
    $customer = Customer::factory()->create(['telegram_id' => 790011]);
    $cart = Cart::factory()->create(['customer_id' => $customer->id, 'expires_at' => now()->addHour()]);
    CartItem::factory()->create(['cart_id' => $cart->id, 'product_id' => $product->id, 'quantity' => 1]);
    $order = app(OrderCheckoutService::class)->checkout($cart, CheckoutPaymentIntents::manualQris());
    app(OrderLifecycle::class)->applyManualCompletion($order);
    $review = ManualFulfillmentReview::where('order_id', $order->id)->sole();
    echo json_encode([
        'path' => route('admin.manual-fulfillment-reviews.show', $review, absolute: false),
        'invoice' => $order->invoice_number,
    ], JSON_THROW_ON_ERROR);
    exit;
}
if (($argv[1] ?? null) === 'margins') {
    $supplier = Supplier::forceCreate([
        'name' => 'Supplier Margin Browser', 'provider' => 'nastele', 'api_key' => 'synthetic-margin-browser-key',
        'currency' => 'VND', 'currency_confirmed' => true, 'exchange_rate_idr' => '0.650001', 'is_active' => true,
        'balance_micros' => 900_000_000_000, 'synced_at' => now(), 'sync_expires_at' => now()->addMinutes(8),
    ]);
    foreach (['A' => ['fixed', 11_000], 'B' => ['manual', 18_500], 'C' => ['manual', 20_000]] as $label => [$mode, $price]) {
        $product = Product::factory()->create(['name' => 'Margin Browser '.$label, 'price_amount' => $price, 'stock' => 0, 'is_active' => true]);
        $item = SupplierCatalogItem::forceCreate([
            'supplier_id' => $supplier->id, 'external_id' => $label, 'name' => 'Private SKU',
            'currency' => 'VND', 'unit_price_micros' => 28_000_000_000, 'stock' => 10,
            'orderable' => true, 'requires_input' => false, 'synced_at' => now(),
        ]);
        ProductSupplierSource::create(['product_id' => $product->id, 'supplier_catalog_item_id' => $item->id, 'priority' => 10, 'is_active' => true]);
        SupplierPricePolicy::create(['product_id' => $product->id, 'mode' => $mode, 'minimum_profit_idr' => 0, 'rounding_idr' => 1]);
    }
    exit;
}
if (($argv[1] ?? null) === 'state') {
    $keys = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR)['keys'];
    $suppliers = Supplier::orderBy('id')->get()->map(fn (Supplier $supplier) => [
        'id' => $supplier->id,
        'provider' => $supplier->provider,
        'key_matches' => array_map(fn (string $key) => $supplier->api_key === $key, $keys),
        'key_encrypted' => $supplier->getRawOriginal('api_key') !== $supplier->api_key,
        'key_storage_digest' => hash('sha256', $supplier->getRawOriginal('api_key')),
    ]);
    if (config('session.driver') !== 'database') {
        throw new RuntimeException('Supplier connection browser state checks require disposable database sessions.');
    }
    $sessions = DB::table(config('session.table'))->get(['payload']);
    $sessionsContainKey = $sessions->contains(function ($session) use ($keys): bool {
        $payload = base64_decode($session->payload, true);
        if ($payload === false) {
            throw new RuntimeException('Supplier connection browser session payload is unreadable.');
        }
        foreach ($keys as $key) {
            if (str_contains($payload, $key)) {
                return true;
            }
        }

        return false;
    });
    echo json_encode([
        'suppliers' => $suppliers,
        'sessions_checked' => $sessions->count(),
        'sessions_contain_key' => $sessionsContainKey,
    ], JSON_THROW_ON_ERROR);
    exit;
}
foreach (['USD' => '16000.000000', 'VND' => '0.650001'] as $currency => $rate) {
    SupplierCurrencyRate::query()->create(['currency' => $currency, 'exchange_rate_idr' => $rate, 'version' => 1]);
}
Supplier::forceCreate(['name' => 'Private source', 'provider' => 'prodseller', 'api_key' => 'synthetic-test-key', 'currency' => 'USDT', 'exchange_rate_idr' => 16000, 'currency_confirmed' => true, 'is_active' => false]);
User::factory()->create(['name' => 'Supplier Browser Owner', 'email' => 'supplier-browser@example.test', 'password' => 'synthetic-browser-password', 'role' => UserRole::Owner]);
echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
