<?php

namespace App\Services\PaidInvoice;

use RuntimeException;

/**
 * Renders the one public-safe paid invoice used by every delivery target.
 *
 * The renderer accepts only the immutable PaidInvoicePresentation seam. It
 * never loads Orders, Products, Payments, or credential relations.
 */
final class PaidInvoiceRenderer
{
    public const WIDTH = 720;

    public const HEIGHT = 720;

    public const MAX_BYTES = 300 * 1024;

    private const TEMPLATE_VERSION = 'nota-b.neo-invoice.v6';

    private const PRODUCT_LAYOUT_TOP = 350;

    private const PRODUCT_LAYOUT_BOTTOM = 510;

    private const PRODUCT_NAME_WIDTH = 270;

    private const PRODUCT_LAYOUT_MAX_CHARS = 128;

    private const PRODUCT_MAX_LINES = 2;

    private const PRODUCT_MIN_FONT_SIZE = 8;

    private const PRODUCT_MAX_FONT_SIZE = 14;

    /** @var list<string> */
    private const SYSTEM_LABELS = [
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
    ];

    /** @var array<string, int> */
    private array $textWidthCache = [];

    /** @var array<string, string> */
    private array $scriptFonts = [];

    private string $font = '';

    private string $boldFont = '';

    private ?\GdImage $logo = null;

    private ?\GdImage $paidStamp = null;

    public function __construct()
    {
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

    public function render(PaidInvoicePresentation $presentation): PaidInvoiceRenderResult
    {
        if (! extension_loaded('gd')
            || ! function_exists('imagecreatetruecolor')
            || ! function_exists('imagettftext')
            || ! function_exists('imagepng')) {
            throw new RuntimeException('Paid invoice rendering requires GD with FreeType and PNG support.');
        }

        $this->textWidthCache = [];
        $metadata = [
            'template_version' => self::TEMPLATE_VERSION,
            ...$presentation->metadata(),
            'system_labels' => self::SYSTEM_LABELS,
        ];
        $displayItems = $presentation->displayItems();
        $currency = $presentation->currency;
        $subtotal = $presentation->subtotal();
        $providerFee = max(0, $presentation->providerFee);
        $totalPaid = max(0, $presentation->totalPaid);

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

            $this->drawHeader($canvas, $colors, $boldFont, $metadata);
            $this->drawPaymentDetails(
                $canvas,
                $colors,
                $font,
                $boldFont,
                192,
                $metadata,
            );
            $layout = $this->productLayout($displayItems, $font);
            $tableBottom = $this->drawProducts(
                $canvas,
                $colors,
                $font,
                $boldFont,
                $layout,
                $currency,
                $presentation->additionalProductCount(),
            );
            $summaryTop = max(474, $tableBottom + 12);
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
            $this->drawFooter($canvas, $colors, $font);

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
            filename: $this->filename($presentation->invoiceId),
            width: self::WIDTH,
            height: self::HEIGHT,
            metadata: $metadata,
        );
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
            'background' => imagecolorallocate($image, 238, 244, 255),
            'paper' => imagecolorallocate($image, 255, 253, 248),
            'paper_edge' => imagecolorallocate($image, 204, 213, 230),
            'ink' => imagecolorallocate($image, 11, 18, 32),
            'muted' => imagecolorallocate($image, 102, 112, 133),
            'line' => imagecolorallocate($image, 169, 180, 196),
            'blue' => imagecolorallocate($image, 0, 92, 255),
            'green_soft' => imagecolorallocate($image, 47, 203, 132),
            'lavender' => imagecolorallocate($image, 178, 157, 255),
            'yellow' => imagecolorallocate($image, 255, 228, 92),
            'pink' => imagecolorallocate($image, 255, 143, 199),
        ];
    }

    /** @param array<string, int> $colors */
    private function drawPaper(\GdImage $image, array $colors): void
    {
        $this->roundedRectangle($image, 8, 8, 718, 718, 18, $colors['lavender']);
        $this->roundedRectangle($image, 2, 2, 714, 714, 18, $colors['paper_edge']);
        $this->roundedRectangle($image, 5, 5, 711, 711, 16, $colors['paper']);
        $this->dashedLine($image, 20, 20, 696, $colors['ink']);
        $this->dashedLine($image, 20, 712, 696, $colors['ink']);
        $this->dashedVerticalLine($image, 20, 20, 712, $colors['ink']);
        $this->dashedVerticalLine($image, 696, 20, 712, $colors['ink']);

        imagefilledpolygon($image, [18, 42, 96, 28, 102, 54, 24, 68], 4, $colors['yellow']);
        imagefilledpolygon($image, [622, 28, 700, 42, 694, 68, 616, 54], 4, $colors['pink']);
    }

    private function drawLogo(\GdImage $canvas): void
    {
        $logo = $this->logo();
        $width = imagesx($logo);
        $height = imagesy($logo);
        $x = 48;

        imagealphablending($canvas, true);
        if (! imagecopy($canvas, $logo, $x, 14, 0, 0, $width, $height)) {
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
    private function drawHeader(\GdImage $image, array $colors, string $boldFont, array $metadata): void
    {
        $this->drawText($image, 'INVOICE', 286, 62, 22, $colors['blue'], $boldFont);
        $this->drawText($image, 'DIGITAL PAYMENT RECEIPT', 289, 84, 9, $colors['muted'], $boldFont);

        $this->roundedRectangle($image, 552, 55, 674, 99, 4, $colors['ink']);
        $this->roundedRectangle($image, 547, 50, 669, 94, 4, $colors['green_soft']);
        $this->drawCenteredInBox($image, '✓ PAID', 547, 50, 669, 94, 13, $colors['ink'], $boldFont);

        imagefilledrectangle($image, 48, 146, 672, 150, $colors['blue']);
        $this->drawText($image, 'INVOICE NO.', 48, 177, 9, $colors['muted'], $boldFont);
        $orderId = (string) $metadata['order_id'];
        $orderSize = $this->fitFontSize($orderId, 470, 14, 9, $boldFont);
        $this->drawText($image, $orderId, 158, 178, $orderSize, $colors['ink'], $boldFont);
    }

    private function drawProducts(
        \GdImage $image,
        array $colors,
        string $font,
        string $boldFont,
        array $layout,
        string $currency,
        int $additionalProductCount,
    ): int {
        $this->drawText($image, 'ORDER DETAILS', 48, 320, 12, $colors['ink'], $boldFont);
        $this->drawText($image, 'PRODUCT', 48, 345, 9, $colors['blue'], $boldFont);
        $this->drawText($image, 'QTY', 348, 345, 9, $colors['blue'], $boldFont);
        $this->drawText($image, 'UNIT PRICE', 414, 345, 9, $colors['blue'], $boldFont);
        $this->drawRightAligned($image, 'LINE TOTAL', 672, 345, 9, $colors['blue'], $boldFont, 570);
        imagefilledrectangle($image, 48, 351, 672, 354, $colors['blue']);

        $cursor = self::PRODUCT_LAYOUT_TOP;
        foreach ($layout['rows'] as $row) {
            $item = $row['item'];
            $bottom = $cursor + $row['height'];
            $baseline = $cursor + $layout['line_height'] + 8;
            $this->drawLines($image, $row['lines'], 48, $baseline, $layout['font_size'], $layout['line_height'], $colors['ink'], $boldFont);
            $this->drawRightAligned($image, (string) $item['quantity'], 390, $baseline, 11, $colors['ink'], $boldFont, 338);
            $this->drawRightAligned($image, $this->money($item['unit_price'], $currency), 535, $baseline, 10, $colors['ink'], $font, 405);
            $this->drawRightAligned($image, $this->money($item['subtotal'], $currency), 672, $baseline, 11, $colors['ink'], $boldFont, 540);
            $this->dashedLine($image, 48, $bottom - 2, 672, $colors['line']);
            $cursor = $bottom;
        }

        if ($additionalProductCount > 0) {
            $this->drawText($image, '+'.$additionalProductCount.' other products', 48, $cursor + 18, 10, $colors['blue'], $boldFont);
            $cursor += 26;
        }

        return max($cursor, 412);
    }

    private function drawPaymentDetails(
        \GdImage $image,
        array $colors,
        string $font,
        string $boldFont,
        int $top,
        array $metadata,
    ): int {
        $this->drawText($image, 'BUYER', 48, $top + 11, 9, $colors['muted'], $boldFont);
        $buyer = (string) ($metadata['buyer_display'] ?? 'Buyer');
        $buyerSize = $this->fitFontSize($buyer, 270, 12, 9, $boldFont);
        $this->drawText($image, $buyer, 48, $top + 36, $buyerSize, $colors['ink'], $boldFont);

        $this->drawText($image, 'PAID AT', 370, $top + 11, 9, $colors['muted'], $boldFont);
        $paidAt = (string) $metadata['paid_at_wib'];
        $paidAtSize = $this->fitFontSize($paidAt, 302, 12, 8, $boldFont);
        $this->drawText($image, $paidAt, 370, $top + 36, $paidAtSize, $colors['ink'], $boldFont);

        $this->drawText($image, 'PAYMENT METHOD', 48, $top + 65, 9, $colors['muted'], $boldFont);
        $method = (string) $metadata['payment_method'];
        $methodSize = $this->fitFontSize($method, 270, 12, 9, $boldFont);
        $this->drawText($image, $method, 48, $top + 90, $methodSize, $colors['ink'], $boldFont);
        $this->dashedLine($image, 48, $top + 106, 672, $colors['line']);

        return $top + 106;
    }

    /**
     * @param  list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>  $items
     * @return array{font_size: int, line_height: int, rows: list<array{item: array{product_name: string, quantity: int, unit_price: int, subtotal: int}, lines: list<string>, height: int}>}
     */
    private function productLayout(array $items, string $font): array
    {
        $availableHeight = self::PRODUCT_LAYOUT_BOTTOM - self::PRODUCT_LAYOUT_TOP;
        $additionalHeight = 0;
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
        $height = $additionalHeight;
        foreach ($items as $item) {
            $lines = $this->boundedProductLines(
                $this->wrapText($this->productLayoutName($item['product_name']), self::PRODUCT_NAME_WIDTH, $fontSize, $font),
                self::PRODUCT_NAME_WIDTH,
                $fontSize,
                $font,
            );
            $rowHeight = max(52, count($lines) * $lineHeight + 20);
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
        $this->drawText($image, 'SUBTOTAL', 238, $top + 15, 10, $colors['muted'], $font);
        $this->drawRightAligned($image, $this->money($subtotal, $currency), 520, $top + 15, 11, $colors['ink'], $boldFont, 392);
        $this->drawText($image, 'PROVIDER / ADMIN FEE', 238, $top + 39, 10, $colors['muted'], $font);
        $this->drawRightAligned($image, $this->money($providerFee, $currency), 520, $top + 39, 11, $colors['ink'], $boldFont, 392);
        imagefilledrectangle($image, 188, $top + 53, 520, $top + 56, $colors['ink']);

        imagefilledrectangle($image, 188, $top + 67, 318, $top + 94, $colors['yellow']);
        $this->drawText($image, 'TOTAL PAID', 196, $top + 88, 12, $colors['ink'], $boldFont);
        $this->drawRightAligned($image, $this->money($totalPaid, $currency), 520, $top + 89, 22, $colors['blue'], $boldFont, 350);

        $this->drawPaidStamp($image, 548, $top + 3, $boldFont);
    }

    private function drawPaidStamp(\GdImage $canvas, int $left, int $top, string $boldFont): void
    {
        $stamp = $this->paidStamp($boldFont);
        if (! imagecopy($canvas, $stamp, $left, $top, 0, 0, imagesx($stamp), imagesy($stamp))) {
            throw new RuntimeException('Paid invoice PAID stamp could not be drawn.');
        }
    }

    private function paidStamp(string $boldFont): \GdImage
    {
        if ($this->paidStamp instanceof \GdImage) {
            return $this->paidStamp;
        }

        $source = imagecreatetruecolor(100, 100);
        if (! $source instanceof \GdImage) {
            throw new RuntimeException('Paid invoice PAID stamp canvas could not be created.');
        }

        imagealphablending($source, false);
        imagesavealpha($source, true);
        $transparent = imagecolorallocatealpha($source, 255, 255, 255, 127);
        imagefilledrectangle($source, 0, 0, 99, 99, $transparent);
        imagealphablending($source, true);

        $stamp = imagecolorallocatealpha($source, 201, 72, 86, 5);
        $stampSoft = imagecolorallocatealpha($source, 255, 238, 239, 112);
        imagefilledellipse($source, 50, 50, 86, 86, $stampSoft);
        imagesetthickness($source, 3);
        imageellipse($source, 50, 50, 86, 86, $stamp);
        imagesetthickness($source, 2);
        imageellipse($source, 50, 50, 74, 74, $stamp);
        imagesetthickness($source, 1);
        for ($angle = 0; $angle < 360; $angle += 24) {
            imagearc($source, 50, 50, 64, 64, $angle, $angle + 12, $stamp);
        }

        $this->drawCenteredInBox($source, 'LHIE STORE', 18, 20, 82, 37, 5, $stamp, $boldFont);
        $this->drawCenteredInBox($source, 'PAID', 12, 34, 88, 68, 15, $stamp, $boldFont);
        $this->drawCenteredInBox($source, 'VERIFIED', 12, 65, 88, 82, 5, $stamp, $boldFont);

        $rotated = imagerotate($source, -13, $transparent);
        imagedestroy($source);
        if (! $rotated instanceof \GdImage) {
            throw new RuntimeException('Paid invoice PAID stamp could not be rotated.');
        }

        imagealphablending($rotated, true);
        imagesavealpha($rotated, true);
        $this->paidStamp = $rotated;

        return $rotated;
    }

    /** @param array<string, int> $colors */
    private function drawFooter(\GdImage $image, array $colors, string $font): void
    {
        $this->dashedLine($image, 48, 662, 520, $colors['line']);
        $this->drawCentered($image, 'Thank you for choosing Lhie Store.', 687, 11, $colors['ink'], $font);
        $this->drawCentered($image, 'Keep this invoice for your records.', 706, 9, $colors['muted'], $font);
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
        if ($text === '' || $this->scriptFonts === [] || preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{0900}-\x{097F}\x{0980}-\x{09FF}\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}\x{3040}-\x{30FF}\x{3400}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $text) !== 1) {
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
            preg_match('/[\x{1F000}-\x{1FAFF}\x{2300}-\x{23FF}\x{2600}-\x{27BF}]/u', $character) === 1 => 'symbols',
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

    private function productLayoutName(string $value): string
    {
        $normalized = $this->normalizeProductName($value);
        if (mb_strlen($normalized, 'UTF-8') <= self::PRODUCT_LAYOUT_MAX_CHARS) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, self::PRODUCT_LAYOUT_MAX_CHARS - 1, 'UTF-8')).'…';
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
     * @return array{default: string, cjk: string, arabic: string, devanagari: string, bengali: string, symbols: string}
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
            'symbols' => $this->optionalFontPath(
                config('paid-invoice.font_symbols_path'),
                [
                    '/usr/share/fonts/truetype/noto/NotoSansSymbols2-Regular.ttf',
                    '/usr/share/fonts/truetype/noto/NotoSansSymbols-Regular.ttf',
                ],
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

    private function dashedVerticalLine(\GdImage $image, int $x, int $top, int $bottom, int $color): void
    {
        for ($y = $top; $y < $bottom; $y += 16) {
            imageline($image, $x, $y, $x, min($bottom, $y + 8), $color);
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
