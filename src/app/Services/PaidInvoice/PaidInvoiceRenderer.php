<?php

namespace App\Services\PaidInvoice;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\PaymentReceiptReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Renders the one public-safe paid invoice used by every delivery target.
 *
 * The renderer deliberately depends on OrderItem snapshots and the canonical
 * paid Payment only. It never follows Product descriptions or credential rows.
 */
final class PaidInvoiceRenderer
{
    public const WIDTH = 720;

    public const HEIGHT = 960;

    public const MAX_BYTES = 300 * 1024;

    private const TEMPLATE_VERSION = 'nota-b.v1';

    private const PRODUCT_LAYOUT_TOP = 438;

    private const PRODUCT_LAYOUT_BOTTOM = 700;

    private const PRODUCT_NAME_WIDTH = 270;

    private const PRODUCT_MAX_LINES = 3;

    private const PRODUCT_MIN_FONT_SIZE = 6;

    private const PRODUCT_MAX_FONT_SIZE = 14;

    private const TIMEZONE = 'Asia/Jakarta';

    /** @var array<int, string> */
    private const MONTHS = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    /** @var array<string, int> */
    private array $textWidthCache = [];

    /** @var array<string, string> */
    private array $scriptFonts = [];

    private string $font = '';

    private string $boldFont = '';

    private ?\GdImage $logo = null;

    public function __construct(
        private readonly PaymentReceiptReport $receiptReport,
    ) {
        if (! extension_loaded('gd') || ! function_exists('imagettfbbox')) {
            return;
        }

        $this->font = $this->fontPath(false);
        $this->boldFont = $this->fontPath(true);
        $this->scriptFonts = $this->resolveScriptFonts($this->font);
        foreach ([$this->font, $this->boldFont, ...array_values($this->scriptFonts)] as $font) {
            $this->warmFont($font);
        }
    }

    public function render(Order $order): PaidInvoiceRenderResult
    {
        if (! extension_loaded('gd')
            || ! function_exists('imagecreatetruecolor')
            || ! function_exists('imagettftext')
            || ! function_exists('imagepng')) {
            throw new RuntimeException('Paid invoice rendering requires GD with FreeType and PNG support.');
        }

        $this->textWidthCache = [];

        $order->loadMissing(['customer', 'orderItems', 'payments']);

        $payment = $this->canonicalPaidPayment($order);
        $items = $this->snapshotItems($order);
        $orderId = $this->orderIdentifier($order);
        $maskedBuyer = $this->maskBuyerTelegramId($order->customer);
        $currency = $this->currency($payment, $order);
        $paidAt = $this->paidAt($payment);
        $paymentMethod = $this->paymentMethod($payment);
        $subtotal = array_sum(array_map(static fn (array $item): int => $item['subtotal'], $items));
        $providerFee = max(0, (int) ($payment->provider_fee ?? 0));
        $totalPaid = $payment->provider_total_payment !== null
            ? max(0, (int) $payment->provider_total_payment)
            : max(0, (int) $order->total_amount);
        $displayItems = array_slice($items, 0, 3);
        $additionalProductCount = max(0, count($items) - count($displayItems));

        $metadata = [
            'template_version' => self::TEMPLATE_VERSION,
            'order_id' => $orderId,
            'buyer_telegram_id' => $maskedBuyer,
            'paid_at_wib' => $this->formatPaidAt($paidAt),
            'payment_method' => $paymentMethod,
            'status' => 'LUNAS',
            'currency' => $currency,
            'items' => $items,
            'display_items' => $displayItems,
            'additional_product_count' => $additionalProductCount,
            'subtotal' => $subtotal,
            'provider_fee' => $providerFee,
            'total_paid' => $totalPaid,
        ];

        $font = $this->font !== '' ? $this->font : $this->fontPath(false);
        $boldFont = $this->boldFont !== '' ? $this->boldFont : $this->fontPath(true);
        $this->scriptFonts = $this->scriptFonts !== [] ? $this->scriptFonts : $this->resolveScriptFonts($font);
        $canvas = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        if (! $canvas instanceof \GdImage) {
            throw new RuntimeException('Paid invoice canvas could not be created.');
        }

        try {
            $colors = $this->colors($canvas);
            imagefilledrectangle($canvas, 0, 0, self::WIDTH - 1, self::HEIGHT - 1, $colors['background']);
            $this->drawPaper($canvas, $colors);
            $this->drawLogo($canvas);

            $this->drawHeader($canvas, $colors, $font, $boldFont, $metadata);
            $layout = $this->productLayout($displayItems, $font);
            $tableBottom = $this->drawProducts(
                $canvas,
                $colors,
                $font,
                $boldFont,
                $layout,
                $currency,
                $additionalProductCount,
            );
            $summaryTop = max(600, $tableBottom + 18);
            $this->drawSummary(
                $canvas,
                $colors,
                $font,
                $boldFont,
                $summaryTop,
                $currency,
                $subtotal,
                $providerFee,
                $totalPaid,
            );
            $this->drawFooter($canvas, $colors, $font, $summaryTop);

            ob_start();
            $encoded = imagepng($canvas, null, 4, PNG_FILTER_NONE);
            $png = ob_get_clean();
            if (! $encoded || ! is_string($png)) {
                throw new RuntimeException('Paid invoice PNG encoding failed.');
            }
        } finally {
            imagedestroy($canvas);
        }

        $this->validatePng($png);

        return new PaidInvoiceRenderResult(
            bytes: $png,
            filename: $this->filename($orderId),
            width: self::WIDTH,
            height: self::HEIGHT,
            metadata: $metadata,
        );
    }

    private function canonicalPaidPayment(Order $order): Payment
    {
        $payments = $order->payments;
        if (! $payments instanceof Collection) {
            throw new InvalidArgumentException('Paid invoice Order payments are not available.');
        }

        $paidPayments = $payments
            ->filter(fn ($payment): bool => $payment instanceof Payment
                && (string) $payment->order_id === (string) $order->getKey()
                && $payment->status === PaymentStatus::Paid
                && $payment->paid_at !== null);

        $canonical = $this->receiptReport->canonicalFromPayments($paidPayments)->first();
        if (! $canonical instanceof Payment) {
            throw new InvalidArgumentException('Paid invoice requires a canonical paid Payment.');
        }

        return $canonical;
    }

    /**
     * @return list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>
     */
    private function snapshotItems(Order $order): array
    {
        $items = $order->orderItems;
        if (! $items instanceof Collection) {
            throw new InvalidArgumentException('Paid invoice Order Items are not available.');
        }

        return $items
            ->filter(fn ($item): bool => $item instanceof OrderItem)
            ->map(static fn (OrderItem $item): array => [
                'product_name' => (string) $item->product_name,
                'quantity' => max(0, (int) $item->quantity),
                'unit_price' => max(0, (int) $item->unit_price),
                'subtotal' => max(0, (int) $item->subtotal),
            ])
            ->values()
            ->all();
    }

    private function orderIdentifier(Order $order): string
    {
        $invoice = trim((string) $order->invoice_number);
        if ($invoice !== '') {
            return $this->presentationText($invoice);
        }

        $key = trim((string) $order->getKey());

        return $key !== '' ? $this->presentationText($key) : 'ORDER';
    }

    private function maskBuyerTelegramId(?Customer $customer): string
    {
        $raw = $customer?->getRawOriginal('telegram_id');
        $identifier = is_scalar($raw) ? trim((string) $raw) : trim((string) ($customer?->telegram_id ?? ''));

        if (! preg_match('/^\d{6,}$/', $identifier)) {
            return 'ID pembeli';
        }

        return substr($identifier, 0, 3).'*****'.substr($identifier, -2);
    }

    private function currency(Payment $payment, Order $order): string
    {
        $currency = trim((string) ($payment->currency ?: $order->currency ?: 'IDR'));

        return strtoupper($currency !== '' ? $currency : 'IDR');
    }

    private function paidAt(Payment $payment): CarbonImmutable
    {
        $value = $payment->paid_at;
        if ($value === null) {
            throw new InvalidArgumentException('Paid invoice Payment timestamp is missing.');
        }

        try {
            return ($value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value)
            )->setTimezone(self::TIMEZONE);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Paid invoice Payment timestamp is invalid.', previous: $exception);
        }
    }

    private function paymentMethod(Payment $payment): string
    {
        $method = strtolower(trim((string) $payment->method));
        $label = trim((string) $payment->payment_method_label);

        $label = match ($method) {
            'api_qris', 'qris' => 'QRIS',
            'binance_pay_id' => 'Binance Pay',
            'hosted' => 'Pakasir',
            default => $label !== '' ? $label : $method,
        };

        $label = $this->presentationText($label);

        return $label !== '' ? $label : 'Tidak diketahui';
    }

    private function formatPaidAt(CarbonInterface $paidAt): string
    {
        $month = self::MONTHS[(int) $paidAt->format('n')] ?? $paidAt->format('F');

        return $paidAt->format('d').' '.$month.' '.$paidAt->format('Y, H:i').' WIB';
    }

    private function filename(string $orderId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', $orderId) ?: 'order';
        $safe = trim($safe, '-_');

        return 'nota-b-'.($safe !== '' ? $safe : 'order').'.png';
    }

    /**
     * @return array<string, int>
     */
    private function colors(\GdImage $image): array
    {
        return [
            'background' => imagecolorallocate($image, 239, 245, 251),
            'paper' => imagecolorallocate($image, 255, 255, 255),
            'paper_edge' => imagecolorallocate($image, 209, 224, 239),
            'ink' => imagecolorallocate($image, 22, 38, 58),
            'muted' => imagecolorallocate($image, 101, 119, 140),
            'line' => imagecolorallocate($image, 199, 215, 231),
            'blue' => imagecolorallocate($image, 24, 109, 214),
            'blue_soft' => imagecolorallocate($image, 231, 242, 255),
            'green' => imagecolorallocate($image, 20, 126, 76),
            'green_soft' => imagecolorallocate($image, 230, 248, 239),
            'stamp' => imagecolorallocate($image, 184, 61, 76),
            'stamp_soft' => imagecolorallocate($image, 255, 238, 239),
        ];
    }

    /** @param array<string, int> $colors */
    private function drawPaper(\GdImage $image, array $colors): void
    {
        $this->roundedRectangle($image, 39, 34, 685, 931, 28, $colors['paper_edge']);
        $this->roundedRectangle($image, 33, 27, 679, 923, 28, $colors['paper']);
        imagefilledrectangle($image, 88, 238, 624, 241, $colors['blue']);
    }

    private function drawLogo(\GdImage $canvas): void
    {
        $logo = $this->logo();
        $width = imagesx($logo);
        $height = imagesy($logo);
        $x = (self::WIDTH - $width) >> 1;

        imagealphablending($canvas, true);
        if (! imagecopy($canvas, $logo, $x, 48, 0, 0, $width, $height)) {
            throw new RuntimeException('Paid invoice branding asset could not be drawn.');
        }
    }

    private function logo(): \GdImage
    {
        if ($this->logo instanceof \GdImage) {
            return $this->logo;
        }

        $source = imagecreatefrompng($this->logoPath());
        if (! $source instanceof \GdImage) {
            throw new RuntimeException('Paid invoice branding asset could not be loaded.');
        }
        imagesavealpha($source, true);
        imagealphablending($source, true);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($source);
            throw new RuntimeException('Paid invoice branding asset has invalid dimensions.');
        }

        $scale = min(190 / $sourceWidth, 132 / $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));
        $logo = imagecreatetruecolor($width, $height);
        if (! $logo instanceof \GdImage) {
            imagedestroy($source);
            throw new RuntimeException('Paid invoice branding cache could not be created.');
        }

        imagealphablending($logo, true);
        $paper = imagecolorallocate($logo, 255, 255, 255);
        imagefilledrectangle($logo, 0, 0, $width - 1, $height - 1, $paper);
        if (! imagecopyresampled($logo, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight)) {
            imagedestroy($source);
            imagedestroy($logo);
            throw new RuntimeException('Paid invoice branding asset could not be drawn.');
        }

        imagedestroy($source);
        $this->logo = $logo;

        return $logo;
    }

    /**
     * @param  array<string, int|string|list<array<string, mixed>>>  $metadata
     * @param  array<string, int>  $colors
     */
    private function drawHeader(\GdImage $image, array $colors, string $font, string $boldFont, array $metadata): void
    {
        $this->drawCentered($image, 'LHIE STORE', 195, 18, $colors['blue'], $boldFont);
        $this->drawCentered($image, 'NOTA PEMBAYARAN', 220, 20, $colors['ink'], $boldFont);
        $this->roundedRectangle($image, 555, 187, 644, 220, 16, $colors['green_soft']);
        $this->drawCenteredInBox($image, 'LUNAS', 555, 187, 644, 220, 13, $colors['green'], $boldFont);

        $this->drawLabelValue($image, 'PEMBELI', (string) $metadata['buyer_telegram_id'], 76, 260, 76, 280, 260, $colors, $font, $boldFont);
        $this->drawLabelValue($image, 'ORDER ID', (string) $metadata['order_id'], 390, 260, 390, 280, 250, $colors, $font, $boldFont);
        $this->drawLabelValue($image, 'WAKTU BAYAR', (string) $metadata['paid_at_wib'], 76, 306, 76, 326, 260, $colors, $font, $boldFont);
        $this->drawLabelValue($image, 'METODE', (string) $metadata['payment_method'], 390, 306, 390, 326, 250, $colors, $font, $boldFont);

        $this->dashedLine($image, 76, 360, 636, $colors['line']);
    }

    /**
     * @param  list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>  $items
     * @return array{font_size: int, line_height: int, rows: list<array{item: array{product_name: string, quantity: int, unit_price: int, subtotal: int}, lines: list<string>, height: int}>}
     */
    private function productLayout(array $items, string $font): array
    {
        $availableHeight = self::PRODUCT_LAYOUT_BOTTOM - self::PRODUCT_LAYOUT_TOP;
        $additionalHeight = count($items) > 0 ? 28 : 0;
        $low = self::PRODUCT_MIN_FONT_SIZE;
        $high = self::PRODUCT_MAX_FONT_SIZE;
        $best = null;

        while ($low <= $high) {
            $fontSize = intdiv($low + $high, 2);
            $candidate = $this->layoutAtFontSize($items, $font, $fontSize, $additionalHeight);
            if ($candidate['height'] <= $availableHeight) {
                $best = $candidate;
                $low = $fontSize + 1;
            } else {
                $high = $fontSize - 1;
            }
        }

        return $best ?? $this->layoutAtFontSize($items, $font, self::PRODUCT_MIN_FONT_SIZE, $additionalHeight);
    }

    /**
     * @param  list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>  $items
     * @return array{font_size: int, line_height: int, height: int, rows: list<array{item: array{product_name: string, quantity: int, unit_price: int, subtotal: int}, lines: list<string>, height: int}>}
     */
    private function layoutAtFontSize(array $items, string $font, int $fontSize, int $additionalHeight): array
    {
        $lineHeight = max(10, (int) round($fontSize * 1.25));
        $rows = [];
        $height = 42 + $additionalHeight;
        foreach ($items as $item) {
            $lines = $this->boundedProductLines(
                $this->wrapText($this->normalizeProductName($item['product_name']), self::PRODUCT_NAME_WIDTH, $fontSize, $font),
                self::PRODUCT_NAME_WIDTH,
                $fontSize,
                $font,
            );
            $rowHeight = max(42, count($lines) * $lineHeight + 18);
            $rows[] = [
                'item' => $item,
                'lines' => $lines,
                'height' => $rowHeight,
            ];
            $height += $rowHeight;
        }

        return [
            'font_size' => $fontSize,
            'line_height' => $lineHeight,
            'height' => $height,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{font_size: int, line_height: int, rows: list<array{item: array{product_name: string, quantity: int, unit_price: int, subtotal: int}, lines: list<string>, height: int}>}  $layout
     * @param  array<string, int>  $colors
     */
    private function drawProducts(
        \GdImage $image,
        array $colors,
        string $font,
        string $boldFont,
        array $layout,
        string $currency,
        int $additionalProductCount,
    ): int {
        $this->drawText($image, 'RINCIAN PESANAN', 76, 394, 14, $colors['blue'], $boldFont);
        $this->drawText($image, 'Produk', 76, 420, 10, $colors['muted'], $boldFont);
        $this->drawText($image, 'Qty', 362, 420, 10, $colors['muted'], $boldFont);
        $this->drawText($image, 'Harga', 414, 420, 10, $colors['muted'], $boldFont);
        $this->drawRightAligned($image, 'Total', 636, 420, 10, $colors['muted'], $boldFont, 84);

        $cursor = self::PRODUCT_LAYOUT_TOP;
        foreach ($layout['rows'] as $row) {
            $item = $row['item'];
            $baseline = $cursor + $layout['line_height'];
            $this->drawLines($image, $row['lines'], 76, $baseline, $layout['font_size'], $layout['line_height'], $colors['ink'], $font);
            $this->drawText($image, (string) $item['quantity'], 362, $baseline, 11, $colors['ink'], $boldFont);
            $this->drawRightAligned($image, $this->money($item['unit_price'], $currency), 523, $baseline, 10, $colors['ink'], $font, 100);
            $this->drawRightAligned($image, $this->money($item['subtotal'], $currency), 636, $baseline, 10, $colors['ink'], $boldFont, 106);
            $this->dashedLine($image, 76, $cursor + $row['height'] - 8, 636, $colors['line']);
            $cursor += $row['height'];
        }

        if ($additionalProductCount > 0) {
            $this->drawText($image, '+'.$additionalProductCount.' produk lainnya', 76, $cursor + 20, 11, $colors['blue'], $font);
            $cursor += 28;
        }

        return max($cursor, 462);
    }

    /** @param array<string, int> $colors */
    private function drawSummary(
        \GdImage $image,
        array $colors,
        string $font,
        string $boldFont,
        int $top,
        string $currency,
        int $subtotal,
        int $providerFee,
        int $totalPaid,
    ): void {
        $this->dashedLine($image, 76, $top, 636, $colors['line']);
        $this->drawText($image, 'RINGKASAN PEMBAYARAN', 250, $top + 19, 12, $colors['blue'], $boldFont);
        $this->roundedRectangle($image, 76, $top + 23, 216, $top + 88, 16, $colors['stamp_soft']);
        $this->roundedRectangle($image, 79, $top + 26, 213, $top + 85, 14, $colors['stamp']);
        $this->drawCenteredInBox($image, 'LUNAS', 79, $top + 29, 213, $top + 60, 15, $colors['stamp_soft'], $boldFont);
        $this->drawCenteredInBox($image, 'TERBAYAR', 79, $top + 58, 213, $top + 82, 8, $colors['stamp_soft'], $font);

        $this->drawText($image, 'Subtotal', 250, $top + 47, 11, $colors['muted'], $font);
        $this->drawRightAligned($image, $this->money($subtotal, $currency), 636, $top + 47, 11, $colors['ink'], $font, 250);
        $this->drawText($image, 'Biaya provider/admin', 250, $top + 73, 11, $colors['muted'], $font);
        $this->drawRightAligned($image, $this->money($providerFee, $currency), 636, $top + 73, 11, $colors['ink'], $font, 250);
        $this->drawText($image, 'TOTAL DIBAYAR', 250, $top + 108, 14, $colors['ink'], $boldFont);
        $this->drawRightAligned($image, $this->money($totalPaid, $currency), 636, $top + 108, 17, $colors['blue'], $boldFont, 250);
    }

    /** @param array<string, int> $colors */
    private function drawFooter(\GdImage $image, array $colors, string $font, int $summaryTop): void
    {
        $lineY = min(854, $summaryTop + 144);
        $this->dashedLine($image, 76, $lineY, 636, $colors['line']);
        $this->drawCentered($image, 'Terima kasih telah memilih Lhie Store', $lineY + 30, 12, $colors['ink'], $font);
        $this->drawCentered($image, 'Dokumen pembayaran digital • Simpan untuk referensi', $lineY + 52, 9, $colors['muted'], $font);
    }

    /** @param array<string, int> $colors */
    private function drawLabelValue(
        \GdImage $image,
        string $label,
        string $value,
        int $labelX,
        int $labelY,
        int $valueX,
        int $valueY,
        int $width,
        array $colors,
        string $font,
        string $boldFont,
    ): void {
        $this->drawText($image, $label, $labelX, $labelY, 8, $colors['muted'], $boldFont);
        $size = $this->fitFontSize($value, $width, 12, 8, $font);
        $this->drawText($image, $value, $valueX, $valueY, $size, $colors['ink'], $font);
    }

    /** @param list<string> $lines */
    private function drawLines(\GdImage $image, array $lines, int $x, int $baseline, int $fontSize, int $lineHeight, int $color, string $font): void
    {
        foreach ($lines as $index => $line) {
            $this->drawTextWithFallback($image, $line, $x, $baseline + ($index * $lineHeight), $fontSize, $color, $font);
        }
    }

    private function drawCentered(\GdImage $image, string $text, int $baseline, float $size, int $color, string $font): void
    {
        $width = $this->textWidth($text, $size, $font);
        $this->drawText($image, $text, (int) round((self::WIDTH - $width) / 2), $baseline, $size, $color, $font);
    }

    private function drawCenteredInBox(\GdImage $image, string $text, int $left, int $top, int $right, int $bottom, float $size, int $color, string $font): void
    {
        $width = $this->textWidth($text, $size, $font);
        $boxCenter = ($left + $right) / 2;
        $x = (int) round($boxCenter - ($width / 2));
        $baseline = (int) round(($top + $bottom) / 2 + ($size * 0.35));
        $this->drawText($image, $text, $x, $baseline, $size, $color, $font);
    }

    private function drawRightAligned(\GdImage $image, string $text, int $right, int $baseline, float $size, int $color, string $font, int $minimumLeft): void
    {
        $size = $this->fitFontSize($text, $right - $minimumLeft, $size, 7, $font);
        $width = $this->textWidth($text, $size, $font);
        $this->drawText($image, $text, max($minimumLeft, (int) round($right - $width)), $baseline, $size, $color, $font);
    }

    private function drawText(\GdImage $image, string $text, float $x, float $baseline, float $size, int $color, string $font): void
    {
        if (imagettftext($image, $size, 0, (int) round($x), (int) round($baseline), $color, $font, $text) === false) {
            throw new RuntimeException('Paid invoice text rendering failed.');
        }
    }

    private function textWidth(string $text, float $size, string $font): int
    {
        $key = $font."\0".(string) $size."\0".$text;
        if (isset($this->textWidthCache[$key])) {
            return $this->textWidthCache[$key];
        }

        $box = imagettfbbox($size, 0, $font, $text);
        if ($box === false) {
            throw new RuntimeException('Paid invoice text measurement failed.');
        }

        $width = max(0, (int) ceil(abs($box[2] - $box[0])));
        $this->textWidthCache[$key] = $width;

        return $width;
    }

    private function textWidthWithFallback(string $text, float $size, string $font): int
    {
        $runs = $this->textRuns($text, $font);
        if (count($runs) === 1) {
            return $this->textWidth($text, $size, $font);
        }

        return array_sum(array_map(function (array $run) use ($size): int {
            if (($this->scriptFonts['cjk'] ?? null) === $run['font']) {
                return (int) ceil(mb_strlen($run['text'], 'UTF-8') * $size * 1.05);
            }

            return $this->textWidth($run['text'], $size, $run['font']);
        }, $runs));
    }

    private function drawTextWithFallback(\GdImage $image, string $text, float $x, float $baseline, float $size, int $color, string $font): void
    {
        $position = $x;
        foreach ($this->textRuns($text, $font) as $run) {
            $this->drawText($image, $run['text'], $position, $baseline, $size, $color, $run['font']);
            $position += $this->textWidth($run['text'], $size, $run['font']);
        }
    }

    /** @return list<array{text: string, font: string}> */
    private function textRuns(string $text, string $defaultFont): array
    {
        if ($text === '' || $this->scriptFonts === [] || preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{0900}-\x{097F}\x{0980}-\x{09FF}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text) !== 1) {
            return [['text' => $text, 'font' => $defaultFont]];
        }

        $runs = [];
        $current = '';
        $currentFont = $defaultFont;
        $previousFont = $defaultFont;
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $font = preg_match('/^\p{M}$/u', $character) === 1
                ? $previousFont
                : $this->fontForCharacter($character, $defaultFont);
            if ($font !== $currentFont && $current !== '') {
                $runs[] = ['text' => $current, 'font' => $currentFont];
                $current = '';
            }
            $current .= $character;
            $currentFont = $font;
            $previousFont = $font;
        }

        if ($current !== '') {
            $runs[] = ['text' => $current, 'font' => $currentFont];
        }

        return $runs === [] ? [['text' => $text, 'font' => $defaultFont]] : $runs;
    }

    private function fontForCharacter(string $character, string $defaultFont): string
    {
        $script = match (true) {
            preg_match('/[\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $character) === 1 => 'cjk',
            preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $character) === 1 => 'arabic',
            preg_match('/[\x{0900}-\x{097F}]/u', $character) === 1 => 'devanagari',
            preg_match('/[\x{0980}-\x{09FF}]/u', $character) === 1 => 'bengali',
            default => 'default',
        };

        return $this->scriptFonts[$script] ?? $defaultFont;
    }

    private function fitFontSize(string $text, int $width, float $preferred, float $minimum, string $font): float
    {
        $size = $preferred;
        while ($size > $minimum && $this->textWidth($text, $size, $font) > $width) {
            $size -= 0.5;
        }

        return max($minimum, $size);
    }

    /** @return list<string> */
    private function wrapText(string $text, int $maxWidth, float $fontSize, string $font): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = explode("\n", $text);
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph === '') {
                $lines[] = '';

                continue;
            }

            $current = '';
            $tokens = preg_split('/(\s+)/u', $paragraph, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$paragraph];
            foreach ($tokens as $token) {
                if ($token === '') {
                    continue;
                }

                if (preg_match('/^\s+$/u', $token) === 1) {
                    if ($current !== '' && $this->textWidthWithFallback($current.$token, $fontSize, $font) <= $maxWidth) {
                        $current .= $token;
                    }

                    continue;
                }

                $candidate = $current.$token;
                if ($current !== '' && $this->textWidthWithFallback($candidate, $fontSize, $font) > $maxWidth) {
                    $lines[] = rtrim($current);
                    $current = '';
                }

                $tokenLines = $this->wrapLongToken($token, $maxWidth, $fontSize, $font);
                if (count($tokenLines) === 1) {
                    $current .= $tokenLines[0];

                    continue;
                }

                foreach (array_slice($tokenLines, 0, -1) as $tokenLine) {
                    $lines[] = $tokenLine;
                }
                $current = end($tokenLines) ?: '';
            }

            $lines[] = rtrim($current);
        }

        return $lines === [] ? [''] : $lines;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function boundedProductLines(array $lines, int $maxWidth, float $fontSize, string $font): array
    {
        if (count($lines) <= self::PRODUCT_MAX_LINES) {
            return $lines;
        }

        $lines = array_slice($lines, 0, self::PRODUCT_MAX_LINES);
        $lastLine = array_pop($lines) ?? '';
        $lines[] = $this->appendEllipsis($lastLine, $maxWidth, $fontSize, $font);

        return $lines;
    }

    private function appendEllipsis(string $line, int $maxWidth, float $fontSize, string $font): string
    {
        $line = rtrim($line);
        $ellipsis = '…';
        if ($line === '') {
            return $ellipsis;
        }

        if ($this->textWidthWithFallback($line.' '.$ellipsis, $fontSize, $font) <= $maxWidth) {
            return $line.' '.$ellipsis;
        }

        $parts = $this->characterWidths($line, $fontSize, $font);
        $ellipsisWidth = $this->textWidthWithFallback($ellipsis, $fontSize, $font);
        $prefix = '';
        $prefixWidth = 0.0;
        foreach ($parts as $part) {
            if ($prefix !== '' && $prefixWidth + $part['width'] + $ellipsisWidth > $maxWidth) {
                break;
            }

            if ($prefix === '' && $maxWidth < $part['width'] + $ellipsisWidth) {
                break;
            }

            $prefix .= $part['character'];
            $prefixWidth += $part['width'];
        }

        $characters = preg_split('//u', $prefix, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while (count($characters) > 1
            && $this->textWidthWithFallback(implode('', $characters).$ellipsis, $fontSize, $font) > $maxWidth) {
            array_pop($characters);
        }

        return rtrim(implode('', $characters)).$ellipsis;
    }

    /**
     * Precompute additive glyph widths once for long-token wrapping; each completed line is checked exactly.
     *
     * @return list<array{character: string, width: float}>
     */
    private function characterWidths(string $text, float $fontSize, string $font): array
    {
        $parts = [];
        foreach ($this->textRuns($text, $font) as $run) {
            foreach (preg_split('//u', $run['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                $width = ($this->scriptFonts['cjk'] ?? null) === $run['font']
                    ? $fontSize * 1.05
                    : (float) $this->textWidth($character, $fontSize, $run['font']);
                $parts[] = ['character' => $character, 'width' => $width];
            }
        }

        return $parts;
    }

    /** @return list<string> */
    private function wrapLongToken(string $token, int $maxWidth, float $fontSize, string $font): array
    {
        if ($this->textWidthWithFallback($token, $fontSize, $font) <= $maxWidth) {
            return [$token];
        }

        $lines = [];
        $line = '';
        $lineWidth = 0.0;
        foreach ($this->characterWidths($token, $fontSize, $font) as $part) {
            if ($line !== '' && $lineWidth + $part['width'] > $maxWidth) {
                $lines[] = $this->fitLineToWidth($line, $maxWidth, $fontSize, $font);
                $line = '';
                $lineWidth = 0.0;
            }

            $line .= $part['character'];
            $lineWidth += $part['width'];
        }

        if ($line !== '') {
            $lines[] = $this->fitLineToWidth($line, $maxWidth, $fontSize, $font);
        }

        return $lines === [] ? [''] : $lines;
    }

    private function fitLineToWidth(string $line, int $maxWidth, float $fontSize, string $font): string
    {
        $characters = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        while (count($characters) > 1
            && $this->textWidthWithFallback(implode('', $characters), $fontSize, $font) > $maxWidth) {
            array_pop($characters);
        }

        return implode('', $characters);
    }

    private function money(int $amount, string $currency): string
    {
        if ($currency === 'USDT') {
            $value = rtrim(rtrim(number_format($amount / 1_000_000, 6, '.', ''), '0'), '.');

            return ($value !== '' ? $value : '0').' USDT';
        }

        $formatted = number_format($amount, 0, ',', '.');

        return $currency === 'IDR' ? 'Rp '.$formatted : $currency.' '.$formatted;
    }

    private function presentationText(string $value): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return trim(is_string($clean) ? $clean : $value);
    }

    private function normalizeProductName(string $value): string
    {
        $clean = $this->presentationText($value);
        $normalized = preg_replace('/\s+/u', ' ', $clean);

        return trim(is_string($normalized) ? $normalized : $clean);
    }

    private function fontPath(bool $bold): string
    {
        $configured = config($bold ? 'paid-invoice.font_bold_path' : 'paid-invoice.font_path');
        $candidates = array_filter([
            is_string($configured) ? $configured : null,
            $bold ? base_path('resources/fonts/DejaVuSans-Bold.ttf') : base_path('resources/fonts/DejaVuSans.ttf'),
            $bold ? '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf' : '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            $bold ? '/usr/share/fonts/TTF/DejaVuSans-Bold.ttf' : '/usr/share/fonts/TTF/DejaVuSans.ttf',
            $bold ? '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf' : '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
            $bold ? '/usr/share/fonts/truetype/liberation2/LiberationSans-Bold.ttf' : '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf',
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Paid invoice TrueType font is unavailable.');
    }

    /**
     * @return array{default: string, cjk: string, arabic: string, devanagari: string, bengali: string}
     */
    private function resolveScriptFonts(string $default): array
    {
        return [
            'default' => $default,
            'cjk' => $this->optionalFontPath(
                config('paid-invoice.font_cjk_path'),
                ['/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'],
                $default,
            ),
            'arabic' => $this->optionalFontPath(
                config('paid-invoice.font_arabic_path'),
                ['/usr/share/fonts/truetype/noto/NotoSansArabic-Regular.ttf'],
                $default,
            ),
            'devanagari' => $this->optionalFontPath(
                config('paid-invoice.font_devanagari_path'),
                ['/usr/share/fonts/truetype/noto/NotoSansDevanagari-Regular.ttf'],
                $default,
            ),
            'bengali' => $this->optionalFontPath(
                config('paid-invoice.font_bengali_path'),
                ['/usr/share/fonts/truetype/noto/NotoSansBengali-Regular.ttf'],
                $default,
            ),
        ];
    }

    /**
     * @param  list<string>  $fallbacks
     */
    private function optionalFontPath(mixed $configured, array $fallbacks, string $default): string
    {
        $candidates = array_filter(
            array_merge(is_string($configured) ? [$configured] : [], $fallbacks),
            static fn (mixed $candidate): bool => is_string($candidate),
        );

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return $default;
    }

    private function warmFont(string $font): void
    {
        imagettfbbox(12, 0, $font, '0');
    }

    private function logoPath(): string
    {
        $configured = config('paid-invoice.logo_path');
        $candidates = array_filter([
            is_string($configured) ? $configured : null,
            resource_path('images/lhie-store-logo.png'),
            public_path('assets/lhie-store-logo-512.png'),
            public_path('assets/lhie-store-logo.png'),
        ]);

        foreach (array_unique($candidates) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Paid invoice Lhie Store branding asset is unavailable.');
    }

    /** @param array<string, int> $colors */
    private function roundedRectangle(\GdImage $image, int $left, int $top, int $right, int $bottom, int $radius, int $color): void
    {
        $radius = min($radius, intdiv($right - $left, 2), intdiv($bottom - $top, 2));
        imagefilledrectangle($image, $left + $radius, $top, $right - $radius, $bottom, $color);
        imagefilledrectangle($image, $left, $top + $radius, $right, $bottom - $radius, $color);
        imagefilledellipse($image, $left + $radius, $top + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $right - $radius, $top + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $left + $radius, $bottom - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $right - $radius, $bottom - $radius, $radius * 2, $radius * 2, $color);
    }

    private function dashedLine(\GdImage $image, int $left, int $y, int $right, int $color): void
    {
        for ($x = $left; $x < $right; $x += 16) {
            imageline($image, $x, $y, min($right, $x + 8), $y, $color);
        }
    }

    private function validatePng(string $png): void
    {
        if (strlen($png) > self::MAX_BYTES || ! str_starts_with($png, "\x89PNG\r\n\x1A\n")) {
            throw new RuntimeException('Paid invoice PNG exceeds the 300 KB budget or is invalid.');
        }

        $dimensions = getimagesizefromstring($png);
        if (! is_array($dimensions)
            || ($dimensions[0] ?? null) !== self::WIDTH
            || ($dimensions[1] ?? null) !== self::HEIGHT
            || ($dimensions['mime'] ?? null) !== 'image/png') {
            throw new RuntimeException('Paid invoice PNG dimensions or format are invalid.');
        }
    }
}
