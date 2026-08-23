<?php

require __DIR__.'/vendor/autoload.php';

// Force environment
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');

$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Artisan;

Artisan::call('migrate:fresh');

use App\Enums\CredentialAccessMethod;
use App\Enums\CredentialFormat;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductStockItem;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Telegram\TelegramReceiptNotifier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

DB::beginTransaction();
try {
    $customer = Customer::factory()->create(['telegram_id' => 123456789, 'locale' => 'id']);
    $product = Product::factory()->create(['name' => 'Akun Premium Manual', 'stock' => 1]);
    $order = Order::factory()->for($customer)->create([
        'order_status' => OrderStatus::Completed,
        'payment_status' => PaymentStatus::Paid,
        'total_amount' => 150000,
        'currency' => 'IDR',
        'invoice_number' => 'INV-'.strtoupper(uniqid()),
    ]);
    $item = OrderItem::factory()->for($order)->create([
        'product_id' => $product->id,
        'product_name' => $product->name,
        'quantity' => 1,
        'unit_price' => 150000,
        'subtotal' => 150000,
    ]);
    Payment::factory()->for($order)->create([
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
        'method' => 'manual',
        'provider' => 'manual',
        'amount' => 150000,
        'currency' => 'IDR',
    ]);

    // Add manual stock with email, password, access token, and tutorial
    ProductStockItem::create([
        'product_id' => $product->id,
        'order_item_id' => $item->id,
        'credential_format' => CredentialFormat::StructuredAccount,
        'credential_payload' => StructuredCredentialPayload::fromValues(
            'buyer@example.com',
            'PasswordRahasia123',
            CredentialAccessMethod::AuthToken,
            'ACCESS_TOKEN_ABC123XYZ',
            "1. Buka aplikasi\n2. Masukkan email dan password\n3. Gunakan Access Token ini di menu pengaturan."
        )->toArray(),
    ]);

    $notifier = app(TelegramReceiptNotifier::class);

    // Generate Invoice Image
    $invoiceUnit = $notifier->renderInvoiceUnit($order->fresh());
    file_put_contents(__DIR__.'/public/test_invoice.png', $invoiceUnit->photoContents());

    // Output the Credential Details
    $detail = $notifier->renderProductDetail($order->fresh());
    echo "=== CREDENTIAL DETAIL TEXT ===\n";
    foreach ($detail['units'] as $unit) {
        echo $unit->text()."\n";
        $markup = $unit->replyMarkup();
        if (! empty($markup)) {
            echo '[BUTTONS]: '.json_encode($markup)."\n";
        }
    }
    echo "==============================\n";
    echo "Invoice saved to src/public/test_invoice.png\n";

} catch (Throwable $e) {
    echo 'Error: '.$e->getMessage()."\n".$e->getTraceAsString();
}
DB::rollBack();
