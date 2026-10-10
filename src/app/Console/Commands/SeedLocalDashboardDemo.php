<?php

namespace App\Console\Commands;

use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Category;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductFamily;
use App\Models\ProductStockItem;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SeedLocalDashboardDemo extends Command
{
    protected $signature = 'dashboard:seed-local-demo';

    protected $description = 'Add labelled demo catalog and 30 days of sales to supplier-local SQLite only.';

    public function handle(): int
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $expected = realpath(database_path('supplier-local.sqlite'));
        if (! app()->environment('supplier-local') || $connection !== 'sqlite'
            || ! is_string($database) || $expected === false || realpath($database) !== $expected) {
            $this->error('Refused: use --env=supplier-local with database/supplier-local.sqlite.');

            return self::FAILURE;
        }

        // Fixtures are initial snapshots, not lifecycle transitions: no observers,
        // provider calls, supplier purchases, or notification dispatches run here.
        $created = Model::withoutEvents(fn () => DB::transaction(fn () => $this->seed()));
        $this->info("Demo ready: {$created} new orders; existing records and settings preserved.");
        $this->info('All demo products/customers are labelled DEMO; invoices start with DEMO-.');

        return self::SUCCESS;
    }

    private function seed(): int
    {
        $now = now();
        $products = [];
        foreach ([
            ['Streaming', 'netflix', 'Netflix Premium', 35000, 2000000, '📺'],
            ['Streaming', 'spotify', 'Spotify Premium', 15000, 900000, '🎵'],
            ['Produktivitas', 'canva', 'Canva Pro', 20000, 1200000, '🎨'],
            ['Produktivitas', 'office', 'Microsoft 365', 25000, 1500000, '📄'],
            ['Editing', 'capcut', 'CapCut Pro', 15000, 950000, '🎬'],
            ['Editing', 'adobe', 'Adobe Express', 10000, 650000, '🖌️'],
        ] as $index => [$categoryName, $slug, $name, $idr, $usdt, $icon]) {
            $category = Category::firstOrCreate(['slug' => 'demo-'.strtolower($categoryName)], [
                'name' => 'DEMO · '.$categoryName, 'description' => 'Kategori simulasi lokal.',
                'sort_order' => 100 + $index, 'is_active' => true,
            ]);
            $family = ProductFamily::firstOrCreate(['slug' => 'demo-'.$slug], [
                'name' => 'DEMO · '.$name, 'description' => 'Katalog simulasi, bukan penawaran produk asli.',
                'sort_order' => 100 + $index,
            ]);
            foreach ([1, 3] as $planIndex => $months) {
                $available = $index === 2 && $planIndex === 1 ? 0 : ($index === 4 ? 2 : 8 + $index * 2);
                $product = Product::firstOrCreate(['name' => '[DEMO] '.$name.' '.$months.' Bulan'], [
                    'category_id' => $category->id, 'product_family_id' => $family->id,
                    'family_sort_order' => $planIndex + 1, 'family_option_label' => $months.' Bulan',
                    'description' => 'Data simulasi dashboard lokal. Akun stok memakai domain .test dan tidak bisa digunakan untuk layanan asli.',
                    'price_amount' => $idr * $months, 'usdt_price_amount' => $usdt * $months,
                    'currency' => 'IDR',
                    'icon' => $icon, 'access_duration' => ($months * 30).' hari',
                    'is_active' => ! ($index === 5 && $planIndex === 1),
                    'delivery_note' => 'DEMO: credential simulasi, jangan dikirim ke pelanggan asli.',
                ]);
                if ($product->wasRecentlyCreated) {
                    $product->forceFill(['stock' => $available, 'reserved_stock' => 0])->save();
                    $openedAt = $now->copy()->startOfDay()->subDays(31);
                    InventoryMovement::create([
                        'product_id' => $product->id, 'type' => InventoryMovementType::Adjustment,
                        'quantity' => $available, 'stock_before' => 0,
                        'available_before' => 0, 'available_after' => $available,
                        'notes' => 'DEMO opening stock', 'created_at' => $openedAt,
                        'stock_notification_sent_at' => $openedAt,
                    ]);
                    for ($unit = 0; $unit < $available; $unit++) {
                        $this->stockItem($product, null, 'available-'.$unit);
                    }
                }
                $products[] = $product;
            }
        }

        $customers = [];
        for ($index = 0; $index < 18; $index++) {
            $customers[] = Customer::firstOrCreate(['telegram_id' => 8000000000000000 + $index], [
                'first_name' => 'DEMO Pelanggan '.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                'username' => 'demo_pelanggan_'.($index + 1),
            ]);
        }

        $created = 0;
        for ($day = 29; $day >= 0; $day--) {
            if (in_array($day, [11, 22], true)) {
                continue;
            }
            $count = 2 + ($day * 7 % 6);
            for ($slot = 0; $slot < $count; $slot++) {
                $date = $now->copy()->startOfDay()->subDays($day);
                $paidAt = $day === 0
                    ? $date->copy()->addSeconds(intdiv(max(0, $now->timestamp - $date->timestamp - 60) * ($slot + 1), $count + 1))
                    : $date->copy()->addHours(9 + $slot * 2)->addMinutes(15);
                $created += $this->order(
                    'DEMO-'.$date->format('Ymd').'-S'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT),
                    $customers[($day + $slot * 3) % count($customers)],
                    $products[($day * 3 + $slot) % count($products)],
                    1 + (($day + $slot) % 3 === 0 ? 1 : 0),
                    $slot % 4 === 0 ? 'USDT' : 'IDR',
                    OrderStatus::Completed, PaymentStatus::Paid, $paidAt->copy()->subMinutes(12), $paidAt,
                );
            }
        }
        foreach ([
            [OrderStatus::Processing, PaymentStatus::Paid],
            [OrderStatus::Processing, PaymentStatus::Paid],
            [OrderStatus::Processing, PaymentStatus::Paid],
            [OrderStatus::PendingPayment, PaymentStatus::Pending],
            [OrderStatus::PendingPayment, PaymentStatus::Pending],
            [OrderStatus::Expired, PaymentStatus::Expired],
            [OrderStatus::Cancelled, PaymentStatus::Failed],
        ] as $index => [$status, $paymentStatus]) {
            $createdAt = $now->copy()->subMinutes(2 + $index * 9);
            $created += $this->order(
                'DEMO-'.$now->format('Ymd').'-OPS'.$index,
                $customers[$index], $products[$index], 1, 'IDR', $status, $paymentStatus,
                $createdAt, $paymentStatus === PaymentStatus::Paid ? $createdAt->copy()->addMinute() : null,
            );
        }

        return $created;
    }

    private function order(string $invoice, Customer $customer, Product $product, int $quantity, string $currency,
        OrderStatus $status, PaymentStatus $paymentStatus, Carbon $createdAt, ?Carbon $paidAt): int
    {
        if (Order::where('invoice_number', $invoice)->exists()) {
            return 0;
        }
        $unitPrice = $currency === 'USDT' ? $product->usdt_price_amount : $product->price_amount;
        $order = Order::forceCreate([
            'invoice_number' => $invoice, 'customer_id' => $customer->id,
            'order_status' => $status, 'payment_status' => $paymentStatus,
            'total_amount' => $unitPrice * $quantity, 'currency' => $currency,
            'expires_at' => $paymentStatus === PaymentStatus::Pending ? now()->addDays(7) : $createdAt->copy()->addMinutes(15),
            'created_at' => $createdAt, 'updated_at' => $paidAt ?? $createdAt,
            'admin_pending_order_notified_at' => $createdAt,
            'admin_paid_order_notified_at' => $paidAt,
            'admin_expired_order_notified_at' => $status === OrderStatus::Expired ? $createdAt : null,
            'telegram_receipt_sent_at' => $status === OrderStatus::Completed ? $paidAt : null,
        ]);
        $item = OrderItem::forceCreate([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name,
            'family_id' => $product->product_family_id, 'family_name' => $product->family->name,
            'quantity' => $quantity, 'local_quantity' => $quantity, 'supplier_quantity' => 0,
            'unit_price' => $product->price_amount, 'subtotal' => $product->price_amount * $quantity,
            'usdt_unit_price' => $product->usdt_price_amount, 'usdt_subtotal' => $product->usdt_price_amount * $quantity,
            'delivery_note' => $product->delivery_note, 'delivery_note_snapshotted' => true,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
        Payment::forceCreate([
            'order_id' => $order->id, 'provider' => 'manual', 'merchant_ref' => $invoice.'-PAY',
            'method' => $currency === 'USDT' ? 'USDT_BEP20' : 'QRIS',
            'amount' => $order->total_amount, 'currency' => $currency, 'status' => $paymentStatus,
            'provider_fee' => 0, 'paid_at' => $paidAt, 'expired_at' => $order->expires_at,
            'created_at' => $createdAt, 'updated_at' => $paidAt ?? $createdAt,
        ]);

        $active = in_array($paymentStatus, [PaymentStatus::Paid, PaymentStatus::Pending], true);
        $before = $product->stock;
        if ($active) {
            InventoryMovement::create([
                'product_id' => $product->id, 'type' => InventoryMovementType::Adjustment,
                'quantity' => $quantity, 'stock_before' => $before,
                'available_before' => $before - $product->reserved_stock,
                'available_after' => $before - $product->reserved_stock + $quantity,
                'notes' => 'DEMO stock for '.$invoice, 'created_at' => $createdAt->copy()->subMinute(),
                'stock_notification_sent_at' => $createdAt,
            ]);
            for ($unit = 0; $unit < $quantity; $unit++) {
                $this->stockItem($product, $item, $invoice.'-'.$unit);
            }
        }
        InventoryMovement::create([
            'product_id' => $product->id, 'order_id' => $order->id,
            'type' => InventoryMovementType::Reservation, 'quantity' => $quantity,
            'notes' => 'DEMO reservation', 'created_at' => $createdAt,
        ]);
        if ($paymentStatus === PaymentStatus::Pending) {
            $product->increment('stock', $quantity);
            $product->increment('reserved_stock', $quantity);
        } else {
            InventoryMovement::create([
                'product_id' => $product->id, 'order_id' => $order->id,
                'type' => $paymentStatus === PaymentStatus::Paid ? InventoryMovementType::Sale : InventoryMovementType::Release,
                'quantity' => -$quantity, 'stock_before' => $before + ($active ? $quantity : 0),
                'notes' => 'DEMO inventory snapshot', 'created_at' => $paidAt ?? $createdAt,
            ]);
        }

        return 1;
    }

    private function stockItem(Product $product, ?OrderItem $item, string $key): void
    {
        ProductStockItem::create([
            'product_id' => $product->id, 'order_item_id' => $item?->id,
            'credential_format' => 'legacy',
            'secret' => 'demo-'.hash('sha256', $key.'-'.$product->id).'@example.test|DEMO-NOT-A-REAL-ACCOUNT',
        ]);
    }
}
