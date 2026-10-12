<?php

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\CustomerAuthMailWork;
use App\Models\CustomerAuthToken;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Customer\CustomerAuthAvailability;
use App\Services\Customer\CustomerTelegramAuthenticationService;
use App\Storefront\StorefrontInvoiceUrl;
use App\Storefront\StorefrontOrderAccess;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_starts_with((string) config('database.connections.sqlite.database'), sys_get_temp_dir().'/customer-account-browser-')) {
    throw new RuntimeException('Account browser fixtures require disposable SQLite.');
}

$operation = $argv[1] ?? '';
if ($operation === 'prepare') {
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        throw new RuntimeException('Account browser migrations failed.');
    }
    echo json_encode(['prepared' => true]);
} elseif ($operation === 'mail') {
    $work = CustomerAuthMailWork::query()->latest('id')->firstOrFail();
    echo json_encode($work->payload, JSON_THROW_ON_ERROR);
} elseif ($operation === 'expire') {
    DB::table('customer_auth_tokens')->where('id', CustomerAuthToken::query()->latest('id')->value('id'))
        ->update(['expires_at' => now()]);
    echo json_encode(['expired' => true]);
} elseif ($operation === 'reset-limits') {
    Cache::flush();
    echo json_encode(['reset' => true]);
} elseif ($operation === 'providers-on' || $operation === 'providers-off') {
    $flag = dirname((string) config('database.connections.sqlite.database')).'/providers-configured';
    if ($operation === 'providers-on') {
        file_put_contents($flag, 'configured');
    } elseif (is_file($flag)) {
        unlink($flag);
    }
    echo json_encode(['configured' => $operation === 'providers-on']);
} elseif ($operation === 'rollback' || $operation === 'enable') {
    app(CustomerAuthAvailability::class)->setEnabled($operation === 'enable');
    echo json_encode(['enabled' => $operation === 'enable']);
} elseif ($operation === 'telegram-only') {
    Customer::factory()->create(['telegram_id' => 7654322, 'email' => null, 'email_verified_at' => null]);
    echo json_encode(['prepared' => true]);
} elseif ($operation === 'telegram-confirm') {
    config(['services.telegram.token' => 'disposable-browser-bot']);
    Http::fake([
        'https://api.telegram.org/*/getMe' => Http::response([
            'ok' => true, 'result' => ['id' => 123456, 'is_bot' => true, 'username' => 'disposable_auth_bot'],
        ]),
    ]);
    $proof = json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR);
    $service = app(CustomerTelegramAuthenticationService::class);
    $actor = $proof['actor'] ?? '7654321';
    $confirmation = $service->prepare($proof['capability'], $actor, $actor, 'private');
    echo json_encode(['confirmed' => $confirmation !== null && $service->confirm($confirmation, $actor, $actor, 'private')]);
} elseif ($operation === 'invoice-delivery') {
    $order = Order::factory()->create([
        'order_status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Paid,
        'total_amount' => 30000,
    ]);
    Payment::factory()->create([
        'order_id' => $order->id, 'provider' => 'manual', 'method' => 'qris',
        'status' => PaymentStatus::Paid, 'paid_at' => now(), 'amount' => 30000,
    ]);
    foreach (['structured', 'legacy', 'raw'] as $format) {
        $product = Product::factory()->create(['name' => 'Disposable '.$format.' account']);
        $item = OrderItem::factory()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => 1, 'unit_price' => 10000, 'subtotal' => 10000,
        ]);
        $stock = ['product_id' => $product->id, 'order_item_id' => $item->id];
        if ($format === 'structured') {
            $stock += [
                'credential_format' => CredentialFormat::StructuredAccount,
                'credential_payload' => StructuredCredentialPayload::fromValues(
                    'structured-invoice@example.test', '  Invoice <safe> 密碼 & spaces  ', CredentialAccessMethod::None,
                )->toArray(),
            ];
        } else {
            $stock['secret'] = $format === 'legacy'
                ? 'legacy-invoice@example.test|Legacy-password-2026'
                : 'Disposable raw credential <script>alert("unsafe")</script>';
        }
        ProductStockItem::create($stock);
    }
    $key = app(StorefrontOrderAccess::class)->issue($order);
    $manual = Order::factory()->create([
        'order_status' => OrderStatus::Completed, 'payment_status' => PaymentStatus::Paid,
    ]);
    Payment::factory()->create([
        'order_id' => $manual->id, 'provider' => 'manual', 'method' => 'qris',
        'status' => PaymentStatus::Paid, 'paid_at' => now(),
    ]);
    $pending = Order::factory()->create([
        'order_status' => OrderStatus::PendingPayment, 'payment_status' => PaymentStatus::Pending,
    ]);
    $pendingProduct = Product::factory()->create();
    $pendingItem = OrderItem::factory()->create([
        'order_id' => $pending->id, 'product_id' => $pendingProduct->id,
    ]);
    ProductStockItem::create([
        'product_id' => $pendingProduct->id, 'order_item_id' => $pendingItem->id,
        'secret' => 'pending-invoice@example.test|NeverRevealPending',
    ]);
    echo json_encode([
        'invoice' => $order->invoice_number, 'key' => $key,
        'invoice_url' => app(StorefrontInvoiceUrl::class)->show($order),
        'access_url' => route('storefront.order-access.show', $order),
        'manual_url' => app(StorefrontInvoiceUrl::class)->show($manual),
        'pending_url' => app(StorefrontInvoiceUrl::class)->show($pending),
    ], JSON_THROW_ON_ERROR);
} else {
    throw new RuntimeException('Unknown account browser operation.');
}
