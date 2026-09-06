<?php

namespace App\Console\Commands;

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
use App\Services\Telegram\TelegramReceiptTxtBenchmark;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class BenchmarkTelegramReceiptTxt extends Command
{
    protected $signature = 'telegram:benchmark-receipt-txt
        {--json : Print the safe benchmark report as JSON}';

    protected $description = 'Run the bounded synthetic TXT receipt benchmark without retaining fixture data.';

    public function handle(TelegramReceiptTxtBenchmark $benchmark): int
    {
        try {
            DB::beginTransaction();
            $report = $benchmark->run($this->fixtures());
            DB::rollBack();
        } catch (Throwable) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            $this->error('Telegram TXT benchmark failed safely.');

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Telegram TXT benchmark: '.($report['pass'] ? 'PASS' : 'FAIL'));
            $this->table(
                ['Items', 'Queries', 'Render ms', 'Bytes', 'RSS Δ', 'Queue ms', 'Telegram ms', 'Effect ms', 'Result'],
                array_map(
                    static fn (array $sample): array => [
                        $sample['item_size'],
                        $sample['db_query_count'],
                        $sample['render_duration_ms'],
                        $sample['output_bytes'],
                        $sample['worker_rss_delta_bytes'],
                        $sample['queue_wait_ms'],
                        $sample['telegram_document_duration_ms'],
                        $sample['effect_completion_time_ms'],
                        $sample['pass'] ? 'PASS' : 'FAIL',
                    ],
                    $report['samples'],
                ),
            );
        }

        return $report['pass'] ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, array{order: Order, customer: Customer}> */
    private function fixtures(): array
    {
        $fixtures = [];

        foreach (TelegramReceiptTxtBenchmark::ITEM_SIZES as $itemSize) {
            $customer = Customer::query()->create([
                'telegram_id' => 9_700_000 + $itemSize,
                'first_name' => 'TXT Benchmark',
                'locale' => 'en',
            ]);
            $order = Order::query()->create([
                'customer_id' => $customer->getKey(),
                'invoice_number' => 'BENCH-TXT-'.$itemSize,
                'order_status' => OrderStatus::Completed,
                'payment_status' => PaymentStatus::Paid,
                'total_amount' => $itemSize * 10_000,
                'currency' => 'IDR',
                'expires_at' => now()->addHour(),
            ]);

            $remaining = $itemSize;
            $itemIndex = 0;
            $credentialIndex = 0;
            while ($remaining > 0) {
                $quantity = min(20, $remaining);
                $itemIndex++;
                $product = Product::query()->create([
                    'name' => 'TXT Benchmark Product '.$itemSize.'-'.$itemIndex,
                    'description' => 'Synthetic benchmark fixture.',
                    'price_amount' => 10_000,
                    'currency' => 'IDR',
                    'is_active' => true,
                ]);
                $product->forceFill([
                    'stock' => $quantity,
                    'reserved_stock' => 0,
                ])->save();

                $orderItem = OrderItem::query()->create([
                    'order_id' => $order->getKey(),
                    'product_id' => $product->getKey(),
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => 10_000,
                    'subtotal' => $quantity * 10_000,
                ]);

                for ($index = 1; $index <= $quantity; $index++) {
                    $credentialIndex++;
                    ProductStockItem::query()->create([
                        'product_id' => $product->getKey(),
                        'order_item_id' => $orderItem->getKey(),
                        'credential_format' => CredentialFormat::StructuredAccount,
                        'credential_payload' => [
                            'version' => 1,
                            'email' => 'benchmark-'.$itemSize.'-'.$credentialIndex.'@example.test',
                            'password' => 'benchmark-password-'.$itemSize.'-'.$credentialIndex,
                            'access_method' => CredentialAccessMethod::None->value,
                            'access_value' => null,
                            'tutorial_override' => null,
                        ],
                    ]);
                }

                $remaining -= $quantity;
            }

            Payment::query()->create([
                'order_id' => $order->getKey(),
                'provider' => 'manual',
                'merchant_ref' => 'BENCH-TXT-PAY-'.$itemSize,
                'method' => 'QR_MANUAL',
                'amount' => $itemSize * 10_000,
                'currency' => 'IDR',
                'status' => PaymentStatus::Paid,
                'paid_at' => now(),
                'expired_at' => now()->addHour(),
            ]);

            $fixtures[$itemSize] = [
                'order' => $order,
                'customer' => $customer,
            ];
        }

        return $fixtures;
    }
}
