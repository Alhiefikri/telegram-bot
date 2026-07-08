<?php

namespace App\Services\Telegram;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Telegram\Concerns\HasAdminWebAppButton;

class TelegramAdminStatsIntents
{
    use HasAdminWebAppButton;

    public function __construct(
        private readonly TelegramBotClient $bot,
    ) {}

    public function showDailyStats(Customer $customer, string $chatId, string $callbackQueryId): void
    {
        $this->bot->answerCallbackQuery($callbackQueryId);
        $this->sendAutomatedDailyStats($chatId);
    }

    public function sendAutomatedDailyStats(string $chatId): void
    {
        $stats = $this->calculateTodayStats();
        $message = $this->formatMessage($stats);
        
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
        $startOfDay = now()->startOfDay();
        $endOfDay = now()->endOfDay();

        $newOrders = Order::query()
            ->whereBetween('created_at', [$startOfDay, $endOfDay])
            ->count();

        // Get paid orders today based on payments
        $paidPayments = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$startOfDay, $endOfDay])
            ->get(['order_id', 'amount', 'provider_fee']);
            
        $paidOrderIds = $paidPayments->pluck('order_id')->unique()->all();
        $paidOrders = count($paidOrderIds);

        // Get total sold products today
        $soldProducts = 0;
        if ($paidOrders > 0) {
            $soldProducts = \App\Models\OrderItem::query()
                ->whereIn('order_id', $paidOrderIds)
                ->sum('quantity');
        }

        // Calculate earnings from payments today
        $earning = $paidPayments->sum(fn (Payment $payment): int => $payment->amount - $payment->provider_fee);

        return [
            'new_orders' => $newOrders,
            'paid_orders' => $paidOrders,
            'sold_products' => (int) $soldProducts,
            'earning' => $earning,
        ];
    }

    /**
     * @param array{new_orders: int, paid_orders: int, sold_products: int, earning: int} $stats
     */
    private function formatMessage(array $stats): string
    {
        $lines = [
            '📊 Statistik Hari Ini',
            '━━━━━━━━━━━━━━',
            'Order Baru: '.$stats['new_orders'],
            'Order Dibayar: '.$stats['paid_orders'],
            'Produk Terjual: '.$stats['sold_products'],
            'Earning: Rp'.number_format($stats['earning'], 0, ',', '.'),
        ];

        return implode("\n", $lines);
    }
}
