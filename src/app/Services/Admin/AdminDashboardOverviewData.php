<?php

namespace App\Services\Admin;

use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\PaymentReceiptReport;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Support\Money;
use App\Support\PaymentProvider;

final class AdminDashboardOverviewData
{
    public function __construct(
        private readonly PaymentReceiptReport $receipts,
        private readonly AdminDashboardSalesData $sales,
        private readonly CheckoutMaintenance $maintenance,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly AdminBalanceOperationsData $balanceOperations,
    ) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function forAdmin(User $user, int $processing, array $filters = []): array
    {
        $days = (string) ($filters['days'] ?? '7') === '30' ? 30 : 7;
        $currency = ($filters['currency'] ?? null) === 'USDT' ? 'USDT' : 'IDR';
        $end = now();
        $start = $end->copy()->startOfDay()->subDays($days - 1);
        $rows = $this->receipts->dailySalesReceipts($start, $end);
        $daily = [];
        $totals = ['IDR' => 0, 'USDT' => 0];
        $todayAmounts = ['IDR' => 0, 'USDT' => 0];
        $todayOrders = 0;
        foreach ($rows as $row) {
            if (! array_key_exists($row['currency'], $totals)) {
                continue;
            }
            $daily[$row['currency']][$row['date']] = $row;
            $totals[$row['currency']] += $row['amount'];
            if ($row['date'] === $end->toDateString()) {
                $todayAmounts[$row['currency']] += $row['amount'];
                $todayOrders += $row['orders'];
            }
        }

        $max = 0;
        $series = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset);
            $row = $daily[$currency][$date->toDateString()] ?? ['amount' => 0, 'orders' => 0];
            $max = max($max, $row['amount']);
            $series[] = [
                'date' => $date->toDateString(),
                'label' => $date->format('d M'),
                'amount' => $row['amount'],
                'formatted' => $this->format($row['amount'], $currency),
                'orders' => $row['orders'],
                'today' => $date->isSameDay($end),
            ];
        }
        foreach ($series as &$point) {
            $point['height'] = $max > 0 ? round($point['amount'] / $max * 100, 4) : 0;
        }
        unset($point);

        return [
            'days' => $days,
            'currency' => $currency,
            'updated_at' => $end->format('d M Y, H:i'),
            'timezone' => (string) config('app.timezone'),
            'today_amount' => $this->format($todayAmounts[$currency], $currency),
            'today_orders' => $todayOrders,
            'processing' => $processing,
            'total' => $this->format($totals[$currency], $currency),
            'series' => $series,
            'has_sales' => array_sum(array_column($series, 'orders')) > 0,
            'axis' => [$this->format($max, $currency), $this->format(intdiv($max, 2), $currency), $this->format(0, $currency)],
            'attention' => $this->attention($user, $processing),
            'recent_orders' => $this->sales->recentOrders(),
        ];
    }

    private function format(int $amount, string $currency): string
    {
        return $currency === 'USDT' ? Money::usdt($amount) : Money::rupiah($amount);
    }

    /** @return list<array{title: string, detail: string, url: string, action: string}> */
    private function attention(User $user, int $processing): array
    {
        $items = [];
        $canManage = (bool) $user->role?->canManagePaymentSettings();
        $settingsUrl = route('admin.dashboard', ['tab' => 'settings', 'section' => 'pembayaran']);
        if ($this->maintenance->current()->active) {
            $items[] = [
                'title' => 'Checkout sedang ditahan',
                'detail' => 'Pelanggan belum bisa membuat pesanan baru. Pesanan yang sudah ada tetap berjalan.',
                'url' => $canManage ? $settingsUrl.'#admin-checkout-maintenance' : route('admin.dashboard', ['tab' => 'penjualan']),
                'action' => $canManage ? 'Periksa pengaturan toko' : 'Lihat pesanan',
            ];
        }
        if ($processing > 0) {
            $items[] = [
                'title' => $processing.' pesanan perlu diproses',
                'detail' => 'Pembayaran diterima, tetapi pengiriman belum selesai.',
                'url' => route('admin.dashboard', ['tab' => 'penjualan', 'sales_filter' => 'processing']),
                'action' => 'Proses pesanan',
            ];
        }
        $provider = ManualPaymentSetting::current()?->paymentProvider() ?? PaymentProvider::MANUAL;
        if ($canManage && (in_array($provider, [PaymentProvider::KLIKQRIS, PaymentProvider::PAKASIR], true)
            || ($provider === PaymentProvider::SAKURUPIAH && ! $this->sakurupiahReadiness->observe()->isReady()))) {
            $items[] = [
                'title' => 'Provider pembayaran perlu diperiksa',
                'detail' => in_array($provider, [PaymentProvider::KLIKQRIS, PaymentProvider::PAKASIR], true)
                    ? PaymentProvider::label($provider).' tidak lagi tersedia untuk dipilih. Ganti provider sebelum membuka pembayaran baru.'
                    : 'Sakurupiah belum siap menerima pembayaran baru. Periksa konfigurasi provider.',
                'url' => $settingsUrl.'#admin-payment-settings',
                'action' => 'Periksa pembayaran',
            ];
        }
        if ($canManage) {
            $operations = $this->balanceOperations->attention();
            if ($operations['restoreGate']['open']) {
                $items[] = [
                    'title' => 'Saldo USDT sedang dalam pemulihan',
                    'detail' => 'Top-up dan belanja USDT baru ditahan sampai pemeriksaan pemulihan selesai.',
                    'url' => route('admin.dashboard', ['tab' => 'settings', 'section' => 'diagnostik']),
                    'action' => 'Periksa pemulihan saldo',
                ];
            }
            $paused = array_filter($operations['pauses'], static fn (array $pause): bool => $pause['paused']);
            if ($paused !== []) {
                $items[] = [
                    'title' => 'Ada layanan saldo yang dijeda',
                    'detail' => implode(', ', array_column($paused, 'label')).'.',
                    'url' => route('admin.dashboard', ['tab' => 'settings', 'section' => 'saldo']).'#admin-usdt-funding-policy',
                    'action' => 'Periksa layanan saldo',
                ];
            }
            if ($operations['alertCounts']['open'] > 0) {
                $items[] = [
                    'title' => $operations['alertCounts']['open'].' peringatan saldo perlu diperiksa',
                    'detail' => $operations['alertCounts']['critical'].' kritis. Detail tersedia di diagnostik saldo.',
                    'url' => route('admin.dashboard', ['tab' => 'settings', 'section' => 'diagnostik']),
                    'action' => 'Periksa peringatan',
                ];
            }
        }

        return $items;
    }
}
