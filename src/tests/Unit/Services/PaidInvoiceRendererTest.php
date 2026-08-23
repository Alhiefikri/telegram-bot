<?php

namespace Tests\Unit\Services;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Services\PaidInvoice\PaidInvoiceOrderPresentationAdapter;
use App\Services\PaidInvoice\PaidInvoiceRenderer;
use App\Services\PaidInvoice\PaidInvoiceRenderResult;
use App\Services\PaidInvoice\PaidInvoiceSyntheticPresentationAdapter;
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

        $result = $this->renderOrder($order);
        $metadata = $result->metadata;
        $decoded = getimagesizefromstring($result->bytes);

        $this->assertSame('image/png', $decoded['mime'] ?? null);
        $this->assertSame(PaidInvoiceRenderer::WIDTH, $decoded[0] ?? null);
        $this->assertSame(PaidInvoiceRenderer::HEIGHT, $decoded[1] ?? null);
        $this->assertLessThanOrEqual(PaidInvoiceRenderer::MAX_BYTES, strlen($result->bytes));
        $this->assertSame('858*****05', $metadata['buyer_telegram_id']);
        $this->assertSame('QRIS', $metadata['payment_method']);
        $this->assertSame('PAID', $metadata['status']);
        $this->assertSame('21 August 2026, 12:34 WIB', $metadata['paid_at_wib']);
        $this->assertSame([
            'INVOICE',
            'DIGITAL PAYMENT RECEIPT',
            'INVOICE NO.',
            'BUYER',
            'PAID AT',
            'PAYMENT METHOD',
            'ORDER DETAILS',
            'PRODUCT',
            'QTY',
            'UNIT PRICE',
            'LINE TOTAL',
            'SUBTOTAL',
            'PROVIDER / ADMIN FEE',
            'TOTAL PAID',
            'PAID',
            'Thank you for choosing Lhie Store.',
            'Keep this invoice for your records.',
        ], $metadata['system_labels']);
        $this->assertSame('Snapshot 東京 — Plan', $metadata['items'][0]['product_name']);
        $this->assertSame(50000, $metadata['subtotal']);
        $this->assertSame(2500, $metadata['provider_fee']);
        $this->assertSame(52500, $metadata['total_paid']);
        $this->assertArrayNotHasKey('discount', $metadata);
        $this->assertStringNotContainsString('Mutable live product', json_encode($metadata, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('8581234505', json_encode($metadata));
        $this->assertStringNotContainsString('secret', json_encode($metadata));
    }

    public function test_synthetic_product_presentation_renders_without_persisted_order_or_payment(): void
    {
        $product = new Product([
            'name' => 'Synthetic USDT Product',
            'price_amount' => 150000,
            'usdt_price_amount' => 1250000,
            'currency' => 'USDT',
        ]);
        $product->setAttribute('id', 77);

        $presentation = app(PaidInvoiceSyntheticPresentationAdapter::class)->present(
            $product,
            CarbonImmutable::parse('2026-08-22 04:00:00 UTC'),
        );

        $this->assertMatchesRegularExpression('/^INV-20260822-[0-9A-Z]{26}$/', $presentation->invoiceId);
        $this->assertLessThanOrEqual(64, mb_strlen($presentation->invoiceId));
        $this->assertMatchesRegularExpression('/^\d{3}\*{5}\d{2}$/', $presentation->buyerIdentifier);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]{2}\*{3}$/', $presentation->buyerName ?? '');
        $this->assertStringNotContainsStringIgnoringCase('fake', $presentation->invoiceId);
        $this->assertStringNotContainsStringIgnoringCase('fake', $presentation->buyerIdentifier);
        $this->assertStringNotContainsStringIgnoringCase('fake', $presentation->buyerName ?? '');
        $this->assertSame('QRIS', $presentation->paymentMethod);
        $this->assertSame('USDT', $presentation->currency);
        $this->assertSame($presentation->buyerName, $presentation->metadata()['buyer_display']);
        $this->assertSame(1, $presentation->items[0]['quantity']);
        $this->assertSame(1_250_000, $presentation->items[0]['subtotal']);
        $this->assertSame(0, $presentation->providerFee);
        $this->assertSame(1_250_000, $presentation->totalPaid);

        $result = app(PaidInvoiceRenderer::class)->render($presentation);
        $decoded = getimagesizefromstring($result->bytes);

        $this->assertSame('image/png', $decoded['mime'] ?? null);
        $this->assertSame(720, $decoded[0] ?? null);
        $this->assertSame(720, $decoded[1] ?? null);
        $this->assertSame('USDT', $result->metadata['currency']);
        $this->assertSame(1_250_000, $result->metadata['total_paid']);
    }

    public function test_three_products_and_additional_products_remain_bounded_in_presentation(): void
    {
        $items = array_map(static fn (int $index): array => [
            'product_name' => 'Product '.$index,
            'quantity' => 1,
            'unit_price' => 1000 * $index,
            'subtotal' => 1000 * $index,
        ], range(1, 5));
        $presentation = app(PaidInvoiceOrderPresentationAdapter::class)->present(
            $this->order(
                customerTelegramId: 8581234505,
                invoiceNumber: 'INV-THREE-ROWS',
                items: $items,
                payment: ['method' => 'qris', 'provider_fee' => 0],
            ),
        );

        $this->assertCount(5, $presentation->items);
        $this->assertCount(3, $presentation->displayItems());
        $this->assertSame(2, $presentation->additionalProductCount());
        $this->assertSame(15_000, $presentation->subtotal());
        $this->assertSame(15_000, $presentation->totalPaid);

        $result = app(PaidInvoiceRenderer::class)->render($presentation);

        $this->assertSame(2, $result->metadata['additional_product_count']);
        $this->assertLessThanOrEqual(PaidInvoiceRenderer::MAX_BYTES, strlen($result->bytes));
    }

    public function test_wrapped_product_name_stays_inside_its_invoice_table_row(): void
    {
        $order = $this->order(
            customerTelegramId: 1241234504,
            invoiceNumber: 'REAL-WRAPPED-PRODUCT',
            items: [[
                'product_name' => 'Real Smoke Manual Account 20260822-163621',
                'quantity' => 1,
                'unit_price' => 150000,
                'subtotal' => 150000,
            ]],
            payment: [
                'method' => 'manual',
                'provider_fee' => 0,
                'provider_total_payment' => null,
                'paid_at' => CarbonImmutable::parse('2026-08-22 08:36:21 UTC'),
            ],
        );

        $result = $this->renderOrder($order);
        $image = imagecreatefromstring($result->bytes);
        $this->assertInstanceOf(\GdImage::class, $image);

        $nameBottom = null;
        $rowLine = null;
        for ($y = 360; $y <= 450; $y++) {
            for ($x = 48; $x <= 330; $x++) {
                $color = imagecolorat($image, $x, $y);
                if (((($color >> 16) & 0xFF) === 11)
                    && ((($color >> 8) & 0xFF) === 18)
                    && (($color & 0xFF) === 32)) {
                    $nameBottom = $y;
                }
            }

            for ($x = 48; $x <= 672 && $rowLine === null; $x++) {
                $color = imagecolorat($image, $x, $y);
                if (((($color >> 16) & 0xFF) === 169)
                    && ((($color >> 8) & 0xFF) === 180)
                    && (($color & 0xFF) === 196)) {
                    $rowLine = $y;
                }
            }
        }

        $this->assertNotNull($nameBottom);
        $this->assertNotNull($rowLine);
        $this->assertLessThan($rowLine, $nameBottom + 4, 'Wrapped Product name overlaps the next invoice row.');
    }

    public function test_telegram_thumbnail_uses_a_square_canvas(): void
    {
        $order = $this->order(
            customerTelegramId: 1241234504,
            invoiceNumber: 'REAL-SQUARE-THUMBNAIL',
            items: [[
                'product_name' => 'Square Telegram Invoice',
                'quantity' => 1,
                'unit_price' => 150000,
                'subtotal' => 150000,
            ]],
            payment: [
                'method' => 'manual',
                'provider_fee' => 0,
                'provider_total_payment' => null,
                'paid_at' => CarbonImmutable::parse('2026-08-22 08:36:21 UTC'),
            ],
        );

        $result = $this->renderOrder($order);
        $decoded = getimagesizefromstring($result->bytes);

        $this->assertSame(720, $decoded[0] ?? null);
        $this->assertSame(720, $decoded[1] ?? null);
    }

    public function test_product_rows_use_an_open_invoice_table_instead_of_filled_cards(): void
    {
        $order = $this->order(
            customerTelegramId: 1241234504,
            invoiceNumber: 'REAL-OPEN-TABLE',
            items: [[
                'product_name' => 'Open Invoice Product Row',
                'quantity' => 1,
                'unit_price' => 150000,
                'subtotal' => 150000,
            ]],
            payment: [
                'method' => 'manual',
                'provider_fee' => 0,
                'provider_total_payment' => null,
                'paid_at' => CarbonImmutable::parse('2026-08-22 08:36:21 UTC'),
            ],
        );

        $result = $this->renderOrder($order);
        $image = imagecreatefromstring($result->bytes);
        $this->assertInstanceOf(\GdImage::class, $image);

        $filledPixels = 0;
        for ($y = 240; $y <= 500; $y++) {
            for ($x = 40; $x <= 680; $x++) {
                $color = imagecolorat($image, $x, $y);
                if (((($color >> 16) & 0xFF) === 226)
                    && ((($color >> 8) & 0xFF) === 239)
                    && (($color & 0xFF) === 255)) {
                    $filledPixels++;
                }
            }
        }

        $this->assertLessThan(3000, $filledPixels, 'Product detail still renders as a thick filled card.');
    }

    public function test_paid_stamp_is_on_the_right_edge_of_the_total_block(): void
    {
        $order = $this->order(
            customerTelegramId: 1241234504,
            invoiceNumber: 'REAL-RIGHT-STAMP',
            items: [[
                'product_name' => 'Right Stamp Telegram Invoice',
                'quantity' => 1,
                'unit_price' => 150000,
                'subtotal' => 150000,
            ]],
            payment: [
                'method' => 'manual',
                'provider_fee' => 0,
                'provider_total_payment' => null,
                'paid_at' => CarbonImmutable::parse('2026-08-22 08:36:21 UTC'),
            ],
        );

        $result = $this->renderOrder($order);

        $this->assertInvoiceRedInkPresentInRegion(
            $result->bytes,
            520,
            430,
            680,
            680,
            'right-aligned PAID stamp',
        );
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

        $metadata = $this->renderOrder($order)->metadata;

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

        $metadata = $this->renderOrder($order)->metadata;

        $this->assertSame('Buyer ID unavailable', $metadata['buyer_telegram_id']);
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

            $result = $this->renderOrder($order);
            $decoded = getimagesizefromstring($result->bytes);

            $this->assertSame('image/png', $decoded['mime'] ?? null, $case);
            $this->assertSame(PaidInvoiceRenderer::WIDTH, $decoded[0] ?? null, $case);
            $this->assertSame(PaidInvoiceRenderer::HEIGHT, $decoded[1] ?? null, $case);
            $this->assertLessThanOrEqual(PaidInvoiceRenderer::MAX_BYTES, strlen($result->bytes), $case);
            $this->assertSame($items[0]['product_name'], $result->metadata['items'][0]['product_name'], $case);
            $this->assertInvoiceColorPresent($result->bytes, [0, 92, 255], 240, $case.' product card');
            $this->assertInvoiceColorPresent($result->bytes, [178, 157, 255], 240, $case.' sticker shadow');
            $this->assertInvoiceColorPresent($result->bytes, [0, 92, 255], 430, $case.' total');
            $this->assertInvoiceRedInkPresentInRegion($result->bytes, 520, 430, 680, PaidInvoiceRenderer::HEIGHT - 1, $case.' paid stamp');
            $this->assertInvoiceColorPresent($result->bytes, [102, 112, 133], 630, $case.' footer');
            $this->assertTotalAndFooterBandsDoNotOverlap($result->bytes, $case);
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

    private function assertInvoiceRedInkPresentInRegion(
        string $png,
        int $left,
        int $top,
        int $right,
        int $bottom,
        string $label,
    ): void {
        $image = imagecreatefromstring($png);
        if (! $image instanceof \GdImage) {
            $this->fail($label.' could not decode its PNG.');
        }

        $found = false;
        try {
            for ($y = $top; $y <= $bottom && ! $found; $y++) {
                for ($x = $left; $x <= $right; $x++) {
                    $color = imagecolorat($image, $x, $y);
                    $red = (($color >> 16) & 0xFF);
                    $green = (($color >> 8) & 0xFF);
                    $blue = ($color & 0xFF);
                    if ($red >= 180 && $green <= 160 && $blue <= 170 && $red - $green >= 40) {
                        $found = true;

                        break;
                    }
                }
            }
        } finally {
            unset($image);
        }

        $this->assertTrue($found, $label.' was not visible inside the expected region.');
    }

    private function assertTotalAndFooterBandsDoNotOverlap(string $png, string $label): void
    {
        $image = imagecreatefromstring($png);
        if (! $image instanceof \GdImage) {
            $this->fail($label.' could not decode its PNG.');
        }

        $totalBottom = null;
        $footerTop = null;
        try {
            for ($y = 500; $y <= 660; $y++) {
                for ($x = 220; $x <= 530; $x++) {
                    $color = imagecolorat($image, $x, $y);
                    if (((($color >> 16) & 0xFF) === 0)
                        && ((($color >> 8) & 0xFF) === 92)
                        && (($color & 0xFF) === 255)) {
                        $totalBottom = $y;
                    }
                }
            }

            for ($y = 620; $y < PaidInvoiceRenderer::HEIGHT && $footerTop === null; $y++) {
                for ($x = 48; $x <= 672; $x++) {
                    $color = imagecolorat($image, $x, $y);
                    if (((($color >> 16) & 0xFF) === 169)
                        && ((($color >> 8) & 0xFF) === 180)
                        && (($color & 0xFF) === 196)) {
                        $footerTop = $y;

                        break;
                    }
                }
            }
        } finally {
            unset($image);
        }

        $this->assertNotNull($totalBottom, $label.' total amount band was not found.');
        $this->assertNotNull($footerTop, $label.' footer separator was not found.');
        $this->assertLessThan($footerTop, $totalBottom + 8, $label.' total amount overlaps the footer separator.');
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
        app(PaidInvoiceOrderPresentationAdapter::class)->present($order);
    }

    private function renderOrder(Order $order): PaidInvoiceRenderResult
    {
        return app(PaidInvoiceRenderer::class)->render(
            app(PaidInvoiceOrderPresentationAdapter::class)->present($order),
        );
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
