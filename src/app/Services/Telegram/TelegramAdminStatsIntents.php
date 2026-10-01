<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Services\Catalog\CatalogFamilyIconResolver;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;
use App\Support\Money;
use Illuminate\Support\Carbon;

class TelegramAdminStatsIntents
{
    use HasAdminWebAppButton;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramStoreStatsReport $statsReport,
        private readonly TelegramMessageChunker $messageChunker,
        private readonly TelegramUiIcons $uiIcons,
        private readonly TelegramProductNameRenderer $productNames,
        private readonly CatalogFamilyIconResolver $familyIcons,
        private readonly TelegramHtmlCaption $html,
    ) {}

    public function showProductStock(string $chatId): void
    {
        $products = Product::query()
            ->where('is_active', true)
            ->withAvailability()
            ->orderBy('name')
            ->get()
            ->sortBy(fn (Product $product) => $product->availableStock())
            ->values();

        if ($products->isEmpty()) {
            $this->bot->sendMessage($chatId, $this->uiIcons->renderLegacyIcons("📦 Stok Produk\n━━━━━━━━━━━━━━\nBelum ada produk aktif."));

            return;
        }

        $icons = $this->familyIcons->forProducts($products);
        $lines = [$this->uiIcons->renderLegacyIcons('📦 Stok Produk'), '━━━━━━━━━━━━━━'];
        foreach ($products as $product) {
            $available = $product->availableStock();
            $icon = $this->uiIcons->renderLegacyIcons($available <= 0 ? '❌' : ($available <= 2 ? '⚠️' : '✅'));
            $line = $icon.' '.$this->productNames->renderSelection($product->name, $icons[(int) $product->getKey()]).': '.($available > 0 ? $available : 'habis');
            if ($product->reserved_stock > 0) {
                $line .= ' ('.$product->reserved_stock.' direservasi)';
            }
            $lines[] = $line;
        }
        $lines[] = '';
        $lines[] = 'Total produk aktif: '.$products->count();

        foreach ($this->messageChunker->fromLines($lines) as $chunk) {
            $this->bot->sendMessage($chatId, $chunk);
        }
    }

    public function showDailyStats(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
    {
        $this->showStoreGrowth($customer, $chatId, $callbackQueryId);
    }

    public function showStoreGrowth(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
    {
        if ($callbackQueryId !== null) {
            $this->bot->answerCallbackQuery($callbackQueryId);
        }

        $this->sendMessageWithDashboard($chatId, $this->formatGrowthMessage($this->statsReport->growth()), ['parse_mode' => 'HTML']);
    }

    public function sendAutomatedDailyStats(string $chatId): void
    {
        $this->sendStats($chatId, $this->calculateYesterdayStats(), '📊 Rekap Kemarin');
    }

    /**
     * @param  array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>}  $stats
     */
    private function sendStats(string $chatId, array $stats, string $title): void
    {
        $this->sendMessageWithDashboard($chatId, $this->formatMessage($stats, $title));
    }

    public function sendDashboardLink(string $chatId): void
    {
        $adminDashboardButton = $this->adminDashboardInlineButton($this->uiIcons->renderLegacyIcons('🌐 Buka Dashboard'));
        if ($adminDashboardButton === null) {
            $this->bot->sendMessage($chatId, 'Dashboard admin belum punya URL HTTPS publik yang valid.');

            return;
        }

        $this->bot->sendMessage($chatId, 'Buka dashboard admin:', [
            'inline_keyboard' => [[$adminDashboardButton]],
        ]);
    }

    private function sendMessageWithDashboard(string $chatId, string $message, array $options = []): void
    {
        $keyboard = [];
        $adminDashboardButton = $this->adminDashboardInlineButton($this->uiIcons->renderLegacyIcons('🌐 Mini App Dashboard'));
        if ($adminDashboardButton !== null) {
            $keyboard[] = [$adminDashboardButton];
        }

        $replyMarkup = $keyboard === [] ? [] : ['inline_keyboard' => $keyboard];

        $this->bot->sendMessage($chatId, $message, $replyMarkup, $options);
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}
     */
    public function calculateTodayStats(): array
    {
        return $this->statsReport->today();
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}
     */
    private function calculateYesterdayStats(): array
    {
        return $this->statsReport->yesterday();
    }

    /**
     * @param  array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>}  $stats
     */
    private function formatMessage(array $stats, string $title): string
    {
        $lines = [
            $this->uiIcons->renderLegacyIcons($title),
            '━━━━━━━━━━━━━━',
            'Order Baru: '.$stats['new_orders'],
            'Order Dibayar: '.$stats['paid_orders'],
            'Produk Terjual: '.$stats['sold_products'],
            'Nilai Penjualan: '.Money::rupiah($stats['sales_value']),
            '',
            'Pembayaran Diterima:',
            ...$this->receiptLines($stats['receipts']),
        ];

        return implode("\n", $lines);
    }

    /**
     * @param  array{report_end_at: Carbon, periods: array<string, array{current: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}, previous: array{new_orders: int, paid_orders: int, sold_products: int, sales_value: int, receipts: array<string, int>, earning: int}}>}  $report
     */
    private function formatGrowthMessage(array $report): string
    {
        $reportEnd = $report['report_end_at']->copy()->timezone('Asia/Jakarta')->locale('id');
        $previousMonth = $reportEnd->copy()->subMonthNoOverflow();
        $lines = [
            '<b>'.$this->html->html($this->uiIcons->renderLegacyIcons('📊 Pertumbuhan Store'), PHP_INT_MAX).'</b>',
            'Diperbarui '.$reportEnd->translatedFormat('d M Y').' · '.$reportEnd->format('H:i').' WIB',
        ];

        foreach ([
            'today' => [
                '☀️ Hari Ini · '.$reportEnd->translatedFormat('d M'),
                'vs kemarin · hingga '.$reportEnd->format('H:i').' WIB',
            ],
            'week' => [
                '🗓 Minggu Ini · '.$reportEnd->copy()->startOfWeek(Carbon::MONDAY)->translatedFormat('d M').'–'.$reportEnd->translatedFormat('d M'),
                'vs minggu lalu · durasi setara',
            ],
            'month' => [
                '📅 Bulan Ini · '.$reportEnd->translatedFormat('F Y'),
                'vs '.$previousMonth->translatedFormat('F Y').' · durasi setara',
            ],
            'last_month' => [
                '🗓 Bulan Kemarin · '.$previousMonth->translatedFormat('F Y'),
                'vs '.$previousMonth->copy()->subMonthNoOverflow()->translatedFormat('F Y').' · bulan penuh',
            ],
        ] as $periodKey => [$title, $comparison]) {
            $period = $report['periods'][$periodKey];
            $current = $period['current'];
            $previous = $period['previous'];

            $lines[] = '';
            $lines[] = '<b>'.$this->html->html($this->uiIcons->renderLegacyIcons($title), PHP_INT_MAX).'</b>';
            $lines[] = '<i>'.$this->html->html($comparison, PHP_INT_MAX).'</i>';
            $body = [
                'Order Baru: '.$current['new_orders'].' ('.$this->changeIndicator($current['new_orders'], $previous['new_orders']).')',
                'Order Dibayar: '.$current['paid_orders'].' ('.$this->changeIndicator($current['paid_orders'], $previous['paid_orders']).')',
                'Produk Terjual: '.$current['sold_products'].' ('.$this->changeIndicator($current['sold_products'], $previous['sold_products']).')',
                'Nilai Penjualan: '.Money::rupiah($current['sales_value']).' ('.$this->changeIndicator($current['sales_value'], $previous['sales_value']).')',
                'Pembayaran Diterima:',
                ...$this->growthReceiptLines($current['receipts'], $previous['receipts']),
            ];
            $lines[] = $this->html->blockquote(implode("\n", $body));
        }

        return implode("\n", $lines);
    }

    private function changeIndicator(int $current, int $previous): string
    {
        if ($previous === 0) {
            return $current === 0 ? $this->uiIcons->renderLegacyIcons('➖').' 0%' : $this->uiIcons->renderLegacyIcons('📈').' Baru';
        }

        $percentage = (($current - $previous) / $previous) * 100;
        if (abs($percentage) < 0.05) {
            return $this->uiIcons->renderLegacyIcons('➖').' 0%';
        }

        $formatted = number_format(abs($percentage), fmod(abs($percentage), 1.0) === 0.0 ? 0 : 1, ',', '.');

        return $percentage > 0
            ? $this->uiIcons->renderLegacyIcons('📈').' +'.$formatted.'%'
            : $this->uiIcons->renderLegacyIcons('📉').' -'.$formatted.'%';
    }

    /**
     * @param  array<string, int>  $currentReceipts
     * @param  array<string, int>  $previousReceipts
     * @return list<string>
     */
    private function growthReceiptLines(array $currentReceipts, array $previousReceipts): array
    {
        $currencies = array_values(array_unique([...array_keys($currentReceipts), ...array_keys($previousReceipts)]));
        sort($currencies);

        $lines = [];
        foreach ($currencies as $currency) {
            $current = $currentReceipts[$currency] ?? 0;
            $previous = $previousReceipts[$currency] ?? 0;
            $lines[] = '• '.$this->receiptCurrencyLabel($currency).': '.$this->formatReceiptAmount($currency, $current).' ('.$this->changeIndicator($current, $previous).')';
        }

        return $lines === [] ? ['• Belum ada'] : $lines;
    }

    private function receiptCurrencyLabel(string $currency): string
    {
        return match ($currency) {
            'IDR' => 'Rupiah',
            default => $currency,
        };
    }

    private function formatReceiptAmount(string $currency, int $amount): string
    {
        return match ($currency) {
            'IDR' => Money::rupiah($amount),
            'USDT' => Money::usdt($amount),
            default => $amount.' '.$currency,
        };
    }

    /**
     * @param  array<string, int>  $receipts
     * @return list<string>
     */
    private function receiptLines(array $receipts): array
    {
        $lines = [];
        if (($receipts['IDR'] ?? 0) > 0) {
            $lines[] = '• Rupiah: '.Money::rupiah($receipts['IDR']);
        }
        if (($receipts['USDT'] ?? 0) > 0) {
            $lines[] = '• USDT: '.Money::usdt($receipts['USDT']);
        }

        foreach ($receipts as $currency => $amount) {
            if (in_array($currency, ['IDR', 'USDT'], true) || $amount <= 0) {
                continue;
            }
            $lines[] = '• '.$currency.': '.$amount.' '.$currency;
        }

        return $lines === [] ? ['• Belum ada'] : $lines;
    }
}
