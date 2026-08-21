<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

class PaidInvoiceRendererTest extends TestCase
{
    public function test_it_outputs_a_valid_public_safe_nota_b_png_from_snapshots(): void
    {
        $order = $this->order(
            customerTelegramId: 8581234505,
            invoiceNumber: 'INV-322-UNICODE',
            items: [[
                'product_name' => 'Snapshot 東京 — Plan',
                'quantity' => 2,
                'unit_price' => 25000,
                'subtotal' => 50000,
            ]],
            payment: [
                'method' => 'qris',
                'provider_fee' => 2500,
                'provider_total_payment' => 52500,
                'paid_at' => CarbonImmutable::parse('2026-08-21 12:34:56 UTC'),
            ],
        );
        $liveProduct = new Product(['name' => 'Mutable live product description with secret']);
        $order->orderItems->first()->setRelation('product', $liveProduct);

        $result = app(PaidInvoiceRenderer::class)->render($order);
        $metadata = $result->metadata;
        $decoded = getimagesizefromstring($result->bytes);

        $this->assertSame('image/png', $decoded['mime'] ?? null);
        $this->assertSame(PaidInvoiceRenderer::WIDTH, $decoded[0] ?? null);
        $this->assertSame(PaidInvoiceRenderer::HEIGHT, $decoded[1] ?? null);
        $this->assertLessThanOrEqual(PaidInvoiceRenderer::MAX_BYTES, strlen($result->bytes));
        $this->assertSame('858*****05', $metadata['buyer_telegram_id']);
        $this->assertSame('QRIS', $metadata['payment_method']);
        $this->assertSame('LUNAS', $metadata['status']);
        $this->assertSame('21 Agustus 2026, 12:34 WIB', $metadata['paid_at_wib']);
        $this->assertSame('Snapshot 東京 — Plan', $metadata['items'][0]['product_name']);
        $this->assertSame(50000, $metadata['subtotal']);
        $this->assertSame(2500, $metadata['provider_fee']);
        $this->assertSame(52500, $metadata['total_paid']);
        $this->assertArrayNotHasKey('discount', $metadata);
        $this->assertStringNotContainsString('Mutable live product', json_encode($metadata, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('8581234505', json_encode($metadata));
        $this->assertStringNotContainsString('secret', json_encode($metadata));
    }

    public function test_it_selects_the_earliest_paid_payment_deterministically(): void
    {
        $order = $this->order(
            customerTelegramId: 812345678,
            invoiceNumber: 'INV-CANONICAL',
            items: [[
                'product_name' => 'Canonical product',
                'quantity' => 1,
                'unit_price' => 50000,
                'subtotal' => 50000,
            ]],
            payment: [
                'method' => 'manual',
                'provider_fee' => 999,
                'provider_total_payment' => 50999,
                'paid_at' => CarbonImmutable::parse('2026-08-21 12:00:00 UTC'),
            ],
        );
        $late = $this->payment($order, [
            'method' => 'BINANCE_PAY_ID',
            'provider_fee' => 500,
            'provider_total_payment' => 50500,
            'paid_at' => CarbonImmutable::parse('2026-08-21 13:00:00 UTC'),
        ]);
        $order->setRelation('payments', new Collection([$late, $order->payments->first()]));

        $metadata = app(PaidInvoiceRenderer::class)->render($order)->metadata;

        $this->assertSame('manual', $metadata['payment_method']);
        $this->assertSame(999, $metadata['provider_fee']);
        $this->assertSame(50999, $metadata['total_paid']);
    }

    public function test_it_keeps_all_snapshot_rows_and_handles_long_unicode_names_and_malformed_ids(): void
    {
        $names = [
            str_repeat('超長い Product 名 ', 14),
            'Produk kedua dengan nama yang panjang dan Unicode — مرحبا',
            'Produk ketiga',
            'Produk keempat',
            'Produk kelima',
        ];
        $items = array_map(static fn (string $name, int $index): array => [
            'product_name' => $name,
            'quantity' => $index + 1,
            'unit_price' => 10000 * ($index + 1),
            'subtotal' => 10000 * ($index + 1) * ($index + 1),
        ], $names, array_keys($names));
        $order = $this->order(
            customerTelegramId: 'bad-id',
            invoiceNumber: 'INV-LONG',
            items: $items,
            payment: [
                'method' => 'manual',
                'provider_fee' => 0,
                'provider_total_payment' => null,
                'paid_at' => CarbonImmutable::parse('2026-08-21 00:00:00 UTC'),
            ],
        );

        $metadata = app(PaidInvoiceRenderer::class)->render($order)->metadata;

        $this->assertSame('ID pembeli', $metadata['buyer_telegram_id']);
        $this->assertCount(5, $metadata['items']);
        $this->assertCount(3, $metadata['display_items']);
        $this->assertSame(2, $metadata['additional_product_count']);
        $this->assertSame($names[0], $metadata['items'][0]['product_name']);
        $this->assertSame($order->total_amount, $metadata['total_paid']);
        $this->assertSame(0, $metadata['provider_fee']);
    }

    public function test_it_keeps_required_invoice_regions_visible_for_pathological_product_names(): void
    {
        $cases = [
            'maximum Unicode name' => [
                [
                    'product_name' => str_repeat('商品🙂', 85),
                    'quantity' => 1,
                    'unit_price' => 10000,
                    'subtotal' => 10000,
                ],
            ],
            'newline-heavy name' => [
                [
                    'product_name' => 'Produkt'.str_repeat("\n", 248),
                    'quantity' => 1,
                    'unit_price' => 20000,
                    'subtotal' => 20000,
                ],
            ],
            'three long rows' => array_map(
                static fn (int $index): array => [
                    'product_name' => str_repeat('Long Product ', 19).'NameTest',
                    'quantity' => $index + 1,
                    'unit_price' => 10000 * ($index + 1),
                    'subtotal' => 10000 * ($index + 1) ** 2,
                ],
                range(0, 2),
            ),
        ];

        foreach ($cases as $case => $items) {
            $order = $this->order(
                customerTelegramId: 8581234505,
                invoiceNumber: 'INV-326-'.strtoupper(str_replace(' ', '-', $case)),
                items: $items,
                payment: [
                    'method' => 'qris',
                    'provider_fee' => 2500,
                    'provider_total_payment' => array_sum(array_column($items, 'subtotal')) + 2500,
                    'paid_at' => CarbonImmutable::parse('2026-08-22 00:00:00 UTC'),
                ],
            );

            $result = app(PaidInvoiceRenderer::class)->render($order);
            $decoded = getimagesizefromstring($result->bytes);

            $this->assertSame('image/png', $decoded['mime'] ?? null, $case);
            $this->assertSame(PaidInvoiceRenderer::WIDTH, $decoded[0] ?? null, $case);
            $this->assertSame(PaidInvoiceRenderer::HEIGHT, $decoded[1] ?? null, $case);
            $this->assertLessThanOrEqual(PaidInvoiceRenderer::MAX_BYTES, strlen($result->bytes), $case);
            $this->assertSame($items[0]['product_name'], $result->metadata['items'][0]['product_name'], $case);
            $this->assertInvoiceColorPresent($result->bytes, [24, 109, 214], 600, $case.' summary');
            $this->assertInvoiceColorPresent($result->bytes, [24, 109, 214], 680, $case.' total');
            $this->assertInvoiceColorPresent($result->bytes, [184, 61, 76], 600, $case.' paid stamp');
            $this->assertInvoiceColorPresent($result->bytes, [101, 119, 140], 740, $case.' footer');
        }
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function assertInvoiceColorPresent(string $png, array $rgb, int $minimumY, string $label): void
    {
        $image = imagecreatefromstring($png);
        if (! $image instanceof \GdImage) {
            $this->fail($label.' could not decode its PNG.');
        }

        $found = false;
        try {
            for ($y = $minimumY; $y < PaidInvoiceRenderer::HEIGHT && ! $found; $y++) {
                for ($x = 0; $x < PaidInvoiceRenderer::WIDTH; $x++) {
                    $color = imagecolorat($image, $x, $y);
                    if ((($color >> 16) & 0xFF) === $rgb[0]
                        && (($color >> 8) & 0xFF) === $rgb[1]
                        && ($color & 0xFF) === $rgb[2]) {
                        $found = true;

                        break;
                    }
                }
            }
        } finally {
            unset($image);
        }

        $this->assertTrue($found, $label.' was not visible inside the canvas.');
    }

    public function test_it_rejects_an_order_without_a_canonical_paid_payment(): void
    {
        $order = $this->order(
            customerTelegramId: 123456789,
            invoiceNumber: 'INV-NOT-PAID',
            items: [],
            payment: [
                'status' => PaymentStatus::Pending,
                'paid_at' => null,
            ],
        );

        $this->expectException(InvalidArgumentException::class);
        app(PaidInvoiceRenderer::class)->render($order);
    }

    /**
     * @param  list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>  $items
     * @param  array<string, mixed>  $payment
     */
    private function order(int|string $customerTelegramId, string $invoiceNumber, array $items, array $payment): Order
    {
        $order = new Order([
            'invoice_number' => $invoiceNumber,
            'total_amount' => array_sum(array_map(static fn (array $item): int => $item['subtotal'], $items)),
            'currency' => 'IDR',
            'payment_status' => PaymentStatus::Paid,
        ]);
        $order->setAttribute('id', 'order-322');
        $customer = new Customer;
        $customer->setRawAttributes(['telegram_id' => $customerTelegramId]);
        $order->setRelation('customer', $customer);

        $orderItems = collect($items)->map(function (array $item) use ($order): OrderItem {
            return new OrderItem([
                'order_id' => $order->getKey(),
                ...$item,
            ]);
        });
        $order->setRelation('orderItems', $orderItems);
        $order->setRelation('payments', new Collection([$this->payment($order, $payment)]));

        return $order;
    }

    /** @param array<string, mixed> $attributes */
    private function payment(Order $order, array $attributes): Payment
    {
        $payment = new Payment([
            'order_id' => $order->getKey(),
            'provider' => 'manual',
            'merchant_ref' => 'merchant-'.$order->getKey().'-'.uniqid(),
            'method' => 'manual',
            'amount' => $order->total_amount,
            'currency' => 'IDR',
            'status' => PaymentStatus::Paid,
            'paid_at' => CarbonImmutable::parse('2026-08-21 12:00:00 UTC'),
            ...$attributes,
        ]);
        $payment->setAttribute('id', (string) str()->uuid());

        return $payment;
    }
}
