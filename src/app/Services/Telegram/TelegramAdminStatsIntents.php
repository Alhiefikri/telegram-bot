<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use App\Models\Product;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;
use App\Support\Money;

class TelegramAdminStatsIntents
{
    use HasAdminWebAppButton;

    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramStoreStatsReport $statsReport,
        private readonly TelegramMessageChunker $messageChunker,
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
            $this->bot->sendMessage($chatId, "📦 Stok Produk\n━━━━━━━━━━━━━━\nBelum ada produk aktif.");

            return;
        }

        $lines = ['📦 Stok Produk', '━━━━━━━━━━━━━━'];
        foreach ($products as $product) {
            $available = $product->availableStock();
            $icon = $available <= 0 ? '❌' : ($available <= 2 ? '⚠️' : '✅');
            $line = $icon.' '.$product->name.': '.($available > 0 ? $available : 'habis');
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
        if ($callbackQueryId !== null) {
            $this->bot->answerCallbackQuery($callbackQueryId);
        }
        $this->sendStats($chatId, $this->calculateTodayStats(), '📊 Statistik Hari Ini');
    }

    public function showStoreGrowth(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
    {
        $this->showDailyStats($customer, $chatId, $callbackQueryId);
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
        $message = $this->formatMessage($stats, $title);

        $keyboard = [];
        $adminDashboardButton = $this->adminDashboardWebAppButton('🌐 Mini App Dashboard');
        if ($adminDashboardButton !== null) {
            $keyboard[] = [$adminDashboardButton];
        }

        $replyMarkup = $keyboard === [] ? [] : ['inline_keyboard' => $keyboard];

        $this->bot->sendMessage($chatId, $message, $replyMarkup);
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
            $title,
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
