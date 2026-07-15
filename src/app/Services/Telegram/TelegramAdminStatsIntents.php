<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;
use Illuminate\Support\Carbon;

class TelegramAdminStatsIntents
{
    use HasAdminWebAppButton;

    public function __construct(
        private readonly TelegramBotClient $bot,
    ) {}

    public function showDailyStats(Customer $customer, string $chatId, ?string $callbackQueryId = null): void
    {
        if ($callbackQueryId !== null) {
            $this->bot->answerCallbackQuery($callbackQueryId);
        }
        $this->sendStats($chatId, $this->calculateTodayStats(), '📊 Statistik Hari Ini');
    }

    public function sendAutomatedDailyStats(string $chatId): void
    {
        $this->sendStats($chatId, $this->calculateYesterdayStats(), '📊 Rekap Kemarin');
    }

    /**
     * @param  array{new_orders: int, paid_orders: int, sold_products: int, earning: int}  $stats
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
     * @return array{new_orders: int, paid_orders: int, sold_products: int, earning: int}
     */
    public function calculateTodayStats(): array
    {
        $reportEndAt = now();
        $reportStartAt = $reportEndAt->copy()->startOfDay();

        return $this->calculateStats($reportStartAt, $reportEndAt, true);
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, earning: int}
     */
    private function calculateYesterdayStats(): array
    {
        $reportEndAt = now()->copy()->startOfDay();
        $reportStartAt = $reportEndAt->copy()->subDay();

        return $this->calculateStats($reportStartAt, $reportEndAt);
    }

    /**
     * @return array{new_orders: int, paid_orders: int, sold_products: int, earning: int}
     */
    private function calculateStats(Carbon $reportStartAt, Carbon $reportEndAt, bool $includeEnd = false): array
    {
        $endOperator = $includeEnd ? '<=' : '<';

        $newOrders = Order::query()
            ->where('created_at', '>=', $reportStartAt)
            ->where('created_at', $endOperator, $reportEndAt)
            ->count();

        // Reporting periods use the configured application timezone and exclude the end boundary.
        $paidPayments = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $reportStartAt)
            ->where('paid_at', $endOperator, $reportEndAt)
            ->get(['order_id', 'amount', 'provider_fee']);

        $paidOrderIds = $paidPayments->pluck('order_id')->unique()->all();
        $paidOrders = count($paidOrderIds);

        // Get total sold products for paid orders in the reporting window.
        $soldProducts = 0;
        if ($paidOrders > 0) {
            $soldProducts = OrderItem::query()
                ->whereIn('order_id', $paidOrderIds)
                ->sum('quantity');
        }

        // Calculate earnings from payments in the reporting window.
        $earning = $paidPayments->sum(fn (Payment $payment): int => $payment->amount - $payment->provider_fee);

        return [
            'new_orders' => $newOrders,
            'paid_orders' => $paidOrders,
            'sold_products' => (int) $soldProducts,
            'earning' => $earning,
        ];
    }

    /**
     * @param  array{new_orders: int, paid_orders: int, sold_products: int, earning: int}  $stats
     */
    private function formatMessage(array $stats, string $title): string
    {
        $lines = [
            $title,
            '━━━━━━━━━━━━━━',
            'Order Baru: '.$stats['new_orders'],
            'Order Dibayar: '.$stats['paid_orders'],
            'Produk Terjual: '.$stats['sold_products'],
            'Earning: Rp'.number_format($stats['earning'], 0, ',', '.'),
        ];

        return implode("\n", $lines);
    }
}
