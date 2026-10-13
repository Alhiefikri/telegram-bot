<?php

namespace App\Services\Admin;

use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\PaymentReceiptReport;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Support\Money;
use App\Support\PaymentProvider;
use Illuminate\Support\Carbon;

final class AdminDashboardOverviewData
{
    public const PERIOD_DAILY = 'harian';

    public const PERIOD_WEEKLY = 'mingguan';

    public const PERIOD_MONTHLY = 'bulanan';

    public const PERIOD_MONTH = 'bulan';

    private const DAILY_WINDOWS = [7, 30];

    private const WEEKLY_BUCKETS = 8;

    private const MONTHLY_BUCKETS = 12;

    private const MONTH_PICKER_BACKLOG_MONTHS = 35;

    public function __construct(
        private readonly PaymentReceiptReport $receipts,
        private readonly AdminDashboardSalesData $sales,
        private readonly CheckoutMaintenance $maintenance,
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly AdminBalanceOperationsData $balanceOperations,
    ) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function forAdmin(User $user, int $processing, array $filters = []): array
    {
        $currency = ($filters['currency'] ?? null) === 'USDT' ? 'USDT' : 'IDR';
        $mode = $this->mode($filters['period'] ?? null);
        $days = $this->dailyWindow($filters['days'] ?? null);
        $end = now();
        $bounds = $this->monthBounds($end);
        $requestedMonth = $this->requestedMonth($filters['month'] ?? null, $bounds, $end->copy()->startOfMonth());

        $period = match ($mode) {
            self::PERIOD_WEEKLY => $this->weeklyPeriod($currency, $end),
            self::PERIOD_MONTHLY => $this->monthlyPeriod($currency, $end),
            self::PERIOD_MONTH => $this->monthPeriod($currency, $requestedMonth, $end),
            default => $this->dailyPeriod($currency, $days, $end),
        };
        $today = $this->todayTotals($currency, $end);

        return [
            'updated_at' => $end->format('d M Y, H:i'),
            'timezone' => (string) config('app.timezone'),
            'currency' => $currency,
            'today_amount' => $this->format($today['amount'], $currency),
            'today_orders' => $today['orders'],
            'processing' => $processing,
            'filter' => [
                'mode' => $mode,
                'days' => $days,
                'month' => $requestedMonth->format('Y-m'),
                'currency' => $currency,
            ],
            'period_options' => $this->periodOptions($mode, $days, $requestedMonth, $currency),
            'period' => $period,
            'months' => $this->monthTiles($currency, $mode === self::PERIOD_MONTH ? $requestedMonth : null, $end, $bounds),
            'attention' => $this->attention($user, $processing),
            'recent_orders' => $this->sales->recentOrders(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, hint: string, url: string, active: bool}>
     */
    private function periodOptions(string $mode, int $days, Carbon $selectedMonth, string $currency): array
    {
        return array_map(fn (array $option): array => $option + ['active' => $option['key'] === $mode], [
            [
                'key' => self::PERIOD_DAILY,
                'label' => 'Harian',
                'hint' => 'Tanggal bayar lunas per hari.',
                'url' => $this->periodUrl(self::PERIOD_DAILY, $days, $selectedMonth, $currency),
            ],
            [
                'key' => self::PERIOD_WEEKLY,
                'label' => 'Mingguan',
                'hint' => self::WEEKLY_BUCKETS.' minggu terakhir, Senin–Minggu.',
                'url' => $this->periodUrl(self::PERIOD_WEEKLY, $days, $selectedMonth, $currency),
            ],
            [
                'key' => self::PERIOD_MONTHLY,
                'label' => 'Bulanan',
                'hint' => self::MONTHLY_BUCKETS.' bulan terakhir.',
                'url' => $this->periodUrl(self::PERIOD_MONTHLY, $days, $selectedMonth, $currency),
            ],
            [
                'key' => self::PERIOD_MONTH,
                'label' => 'Bulan tertentu',
                'hint' => 'Per hari dalam satu bulan pilihan.',
                'url' => $this->periodUrl(self::PERIOD_MONTH, $days, $selectedMonth, $currency),
            ],
        ]);
    }

    private function periodUrl(string $mode, int $days, Carbon $selectedMonth, string $currency): string
    {
        return route('admin.dashboard', [
            'tab' => 'stats',
            'period' => $mode,
            'days' => $days,
            'month' => $selectedMonth->format('Y-m'),
            'currency' => $currency,
        ]);
    }

    private function mode(mixed $requested): string
    {
        return in_array($requested, [self::PERIOD_DAILY, self::PERIOD_WEEKLY, self::PERIOD_MONTHLY, self::PERIOD_MONTH], true)
            ? (string) $requested
            : self::PERIOD_DAILY;
    }

    private function dailyWindow(mixed $requested): int
    {
        return (int) $requested === 30 ? 30 : self::DAILY_WINDOWS[0];
    }

    /**
     * The month picker stays bounded to the recent past so a hand-crafted
     * query string cannot request an unbounded month window.
     *
     * @return array{min: string, max: string}
     */
    private function monthBounds(Carbon $end): array
    {
        return [
            'min' => $end->copy()->startOfMonth()->subMonthsNoOverflow(self::MONTH_PICKER_BACKLOG_MONTHS)->format('Y-m'),
            'max' => $end->copy()->format('Y-m'),
        ];
    }

    private function requestedMonth(mixed $requested, array $bounds, Carbon $fallback): Carbon
    {
        $value = is_string($requested) ? trim($requested) : '';
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value) !== 1) {
            return $fallback;
        }
        if ($value < $bounds['min'] || $value > $bounds['max']) {
            return $fallback;
        }

        return Carbon::createFromFormat('Y-m-d', $value.'-01')->startOfMonth();
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyPeriod(string $currency, int $days, Carbon $end): array
    {
        $start = $end->copy()->startOfDay()->subDays($days - 1);
        $rows = $this->currencyRows($this->receipts->dailySalesReceipts($start, $end), $currency, 'date');
        $points = [];
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->copy()->addDays($offset);
            $row = $rows[$date->toDateString()] ?? null;
            $points[] = $this->point(
                currency: $currency,
                key: $date->toDateString(),
                label: $this->dayLabel($date),
                rangeLabel: $this->longDayLabel($date),
                amount: (int) ($row['amount'] ?? 0),
                orders: (int) ($row['orders'] ?? 0),
                isCurrent: $date->isSameDay($end),
            );
        }

        return $this->period($currency, $points, [
            'mode' => self::PERIOD_DAILY,
            'mode_label' => 'Harian',
            'granularity' => 'day',
            'bucket_label' => 'hari',
            'days' => $days,
            'window_label' => $days.' hari terakhir',
            'range_label' => $this->longDayLabel($start).' – '.$this->longDayLabel($end),
            'partial_label' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function weeklyPeriod(string $currency, Carbon $end): array
    {
        $firstWeek = $end->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(self::WEEKLY_BUCKETS - 1);
        $rows = $this->currencyRows($this->receipts->dailySalesReceipts($firstWeek, $end), $currency, 'date');
        $points = [];
        for ($offset = 0; $offset < self::WEEKLY_BUCKETS; $offset++) {
            $weekStart = $firstWeek->copy()->addWeeks($offset);
            $weekEnd = $weekStart->copy()->endOfWeek(Carbon::SUNDAY);
            $amount = 0;
            $orders = 0;
            for ($day = 0; $day < 7; $day++) {
                $date = $weekStart->copy()->addDays($day);
                if ($date->greaterThan($end)) {
                    break;
                }
                $row = $rows[$date->toDateString()] ?? null;
                $amount += (int) ($row['amount'] ?? 0);
                $orders += (int) ($row['orders'] ?? 0);
            }
            $points[] = $this->point(
                currency: $currency,
                key: $weekStart->toDateString(),
                label: $this->dayLabel($weekStart),
                rangeLabel: $this->dayLabel($weekStart).' – '.$this->longDayLabel($weekEnd),
                amount: $amount,
                orders: $orders,
                isCurrent: $weekStart->equalTo($end->copy()->startOfWeek(Carbon::MONDAY)),
            );
        }

        return $this->period($currency, $points, [
            'mode' => self::PERIOD_WEEKLY,
            'mode_label' => 'Mingguan',
            'granularity' => 'week',
            'bucket_label' => 'minggu',
            'days' => null,
            'window_label' => self::WEEKLY_BUCKETS.' minggu terakhir',
            'range_label' => $this->longDayLabel($firstWeek).' – '.$this->longDayLabel($end),
            'partial_label' => 'Minggu berjalan masih berlangsung',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function monthlyPeriod(string $currency, Carbon $end): array
    {
        $firstMonth = $end->copy()->startOfMonth()->subMonthsNoOverflow(self::MONTHLY_BUCKETS - 1);
        $rows = $this->currencyRows($this->receipts->monthlySalesReceipts($firstMonth, $end), $currency, 'month');
        $points = [];
        for ($offset = 0; $offset < self::MONTHLY_BUCKETS; $offset++) {
            $monthStart = $firstMonth->copy()->addMonthsNoOverflow($offset);
            $row = $rows[$monthStart->format('Y-m')] ?? null;
            $points[] = $this->point(
                currency: $currency,
                key: $monthStart->format('Y-m'),
                label: $this->monthLabel($monthStart),
                rangeLabel: $this->longMonthLabel($monthStart),
                amount: (int) ($row['amount'] ?? 0),
                orders: (int) ($row['orders'] ?? 0),
                isCurrent: $monthStart->isSameMonth($end),
            );
        }

        return $this->period($currency, $points, [
            'mode' => self::PERIOD_MONTHLY,
            'mode_label' => 'Bulanan',
            'granularity' => 'month',
            'bucket_label' => 'bulan',
            'days' => null,
            'window_label' => self::MONTHLY_BUCKETS.' bulan terakhir',
            'range_label' => $this->longMonthLabel($firstMonth).' – '.$this->longMonthLabel($end),
            'partial_label' => 'Bulan berjalan masih berlangsung',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function monthPeriod(string $currency, Carbon $monthStart, Carbon $end): array
    {
        $isCurrentMonth = $monthStart->isSameMonth($end);
        $windowEnd = $isCurrentMonth ? $end->copy() : $monthStart->copy()->endOfMonth();
        $rows = $this->currencyRows($this->receipts->dailySalesReceipts($monthStart, $windowEnd), $currency, 'date');
        $points = [];
        $lastDay = $windowEnd->copy()->startOfDay();
        for ($date = $monthStart->copy(); $date->lessThanOrEqualTo($lastDay); $date->addDay()) {
            $row = $rows[$date->toDateString()] ?? null;
            $points[] = $this->point(
                currency: $currency,
                key: $date->toDateString(),
                label: $this->dayLabel($date),
                rangeLabel: $this->longDayLabel($date),
                amount: (int) ($row['amount'] ?? 0),
                orders: (int) ($row['orders'] ?? 0),
                isCurrent: $date->isSameDay($end),
            );
        }

        return $this->period($currency, $points, [
            'mode' => self::PERIOD_MONTH,
            'mode_label' => 'Bulan tertentu',
            'granularity' => 'day',
            'bucket_label' => 'hari',
            'days' => null,
            'month' => $monthStart->format('Y-m'),
            'window_label' => $this->longMonthLabel($monthStart),
            'range_label' => $this->longDayLabel($monthStart).' – '.$this->longDayLabel($lastDay),
            'partial_label' => $isCurrentMonth ? 'Bulan berjalan masih berlangsung' : null,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $points
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function period(string $currency, array $points, array $meta): array
    {
        $max = 0;
        $total = 0;
        $orders = 0;
        $peakIndex = null;
        foreach ($points as $index => $point) {
            if ($point['amount'] > $max) {
                $max = $point['amount'];
                $peakIndex = $index;
            }
            $total += $point['amount'];
            $orders += $point['orders'];
        }
        $scale = max($max, 1);
        foreach ($points as $index => $point) {
            $points[$index]['height'] = round($point['amount'] / $scale * 100, 4);
            $points[$index]['share'] = $total > 0 ? round($point['amount'] / $total * 100, 1) : 0.0;
            $points[$index]['is_peak'] = $index === $peakIndex;
        }
        $buckets = count($points);
        $average = $buckets > 0 ? intdiv($total, $buckets) : 0;

        return $meta + [
            'points' => $points,
            'buckets' => $buckets,
            'max_amount' => $max,
            'total_amount' => $total,
            'total' => $this->format($total, $currency),
            'orders' => $orders,
            'average_amount' => $average,
            'average' => $this->format($average, $currency),
            'average_height' => round($average / $scale * 100, 4),
            'peak' => $peakIndex === null || $max <= 0 ? null : [
                'label' => $points[$peakIndex]['range_label'],
                'formatted' => $points[$peakIndex]['formatted'],
                'orders' => $points[$peakIndex]['orders'],
            ],
            'has_sales' => $orders > 0,
            'axis' => [
                $this->format($max, $currency),
                $this->format(intdiv($max, 2), $currency),
                $this->format(0, $currency),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function point(string $currency, string $key, string $label, string $rangeLabel, int $amount, int $orders, bool $isCurrent): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'range_label' => $rangeLabel,
            'amount' => $amount,
            'formatted' => $this->format($amount, $currency),
            'orders' => $orders,
            'is_current' => $isCurrent,
        ];
    }

    /**
     * @param  list<array{currency: string, amount: int, orders: int}>  $rows
     * @return array<string, array{amount: int, orders: int}>
     */
    private function currencyRows(array $rows, string $currency, string $key): array
    {
        $indexed = [];
        foreach ($rows as $row) {
            if ($row['currency'] !== $currency) {
                continue;
            }
            $indexed[$row[$key]] ??= ['amount' => 0, 'orders' => 0];
            $indexed[$row[$key]]['amount'] += $row['amount'];
            $indexed[$row[$key]]['orders'] += $row['orders'];
        }

        return $indexed;
    }

    /**
     * Today's receipt total for the selected currency while the paid-order
     * count stays currency-independent, matching the dashboard caption.
     *
     * @return array{amount: int, orders: int}
     */
    private function todayTotals(string $currency, Carbon $end): array
    {
        $amount = 0;
        $orders = 0;
        foreach ($this->receipts->dailySalesReceipts($end->copy()->startOfDay(), $end) as $row) {
            $orders += $row['orders'];
            if ($row['currency'] === $currency) {
                $amount += $row['amount'];
            }
        }

        return ['amount' => $amount, 'orders' => $orders];
    }

    /**
     * @param  array{min: string, max: string}  $bounds
     * @return array<string, mixed>
     */
    private function monthTiles(string $currency, ?Carbon $selectedMonth, Carbon $end, array $bounds): array
    {
        $currentMonth = $end->copy()->startOfMonth();
        $previousMonth = $currentMonth->copy()->subMonthsNoOverflow();
        $recent = $this->monthTotals($currency, $previousMonth, $end);

        $tiles = [
            'bounds' => $bounds,
            'current' => $this->monthTile($currency, $currentMonth, $recent[$currentMonth->format('Y-m')] ?? null, $previousMonth, $recent),
            'previous' => $this->monthTile($currency, $previousMonth, $recent[$previousMonth->format('Y-m')] ?? null, null, []),
            'selected' => null,
        ];
        if ($selectedMonth === null || $selectedMonth->isSameMonth($currentMonth)) {
            return $tiles;
        }

        $selectedPrevious = $selectedMonth->copy()->subMonthsNoOverflow();
        $selectedTotals = $this->monthTotals($currency, $selectedPrevious, $selectedMonth->copy()->endOfMonth());
        $tiles['selected'] = $this->monthTile(
            $currency,
            $selectedMonth,
            $selectedTotals[$selectedMonth->format('Y-m')] ?? null,
            $selectedPrevious,
            $selectedTotals,
        );

        return $tiles;
    }

    /** @return array<string, array{amount: int, orders: int}> */
    private function monthTotals(string $currency, Carbon $start, Carbon $end): array
    {
        return $this->currencyRows($this->receipts->monthlySalesReceipts($start, $end), $currency, 'month');
    }

    /**
     * @param  array{amount: int, orders: int}|null  $row
     * @param  array<string, array{amount: int, orders: int}>  $comparisonRows
     * @return array<string, mixed>
     */
    private function monthTile(string $currency, Carbon $monthStart, ?array $row, ?Carbon $comparisonMonth, array $comparisonRows): array
    {
        $amount = (int) ($row['amount'] ?? 0);
        $orders = (int) ($row['orders'] ?? 0);
        $delta = null;
        if ($comparisonMonth !== null) {
            $comparison = $comparisonRows[$comparisonMonth->format('Y-m')]['amount'] ?? 0;
            $delta = $this->delta($amount, $comparison, $comparisonMonth);
        }

        return [
            'key' => $monthStart->format('Y-m'),
            'label' => $this->longMonthLabel($monthStart),
            'short_label' => $this->monthLabel($monthStart),
            'amount' => $amount,
            'total' => $this->format($amount, $currency),
            'orders' => $orders,
            'is_current' => $monthStart->isSameMonth(now()),
            'delta' => $delta,
        ];
    }

    /**
     * @return array{amount: int, percent: float|null, label: string, tone: string, compare_label: string}
     */
    private function delta(int $amount, int $comparison, Carbon $comparisonMonth): array
    {
        $compareLabel = 'vs '.$this->longMonthLabel($comparisonMonth);
        if ($comparison <= 0) {
            return [
                'amount' => $amount - $comparison,
                'percent' => null,
                'label' => $amount > 0 ? 'belum ada pembanding' : 'sama',
                'tone' => 'flat',
                'compare_label' => $compareLabel,
            ];
        }

        $percent = round(($amount - $comparison) / $comparison * 100, 1);

        return [
            'amount' => $amount - $comparison,
            'percent' => $percent,
            'label' => ($percent > 0 ? '+' : ($percent < 0 ? '-' : '')).number_format(abs($percent), 1, ',', '.').'%',
            'tone' => $percent > 0 ? 'up' : ($percent < 0 ? 'down' : 'flat'),
            'compare_label' => $compareLabel,
        ];
    }

    private function dayLabel(Carbon $date): string
    {
        return $date->format('d M');
    }

    private function longDayLabel(Carbon $date): string
    {
        return $date->format('d M Y');
    }

    private function monthLabel(Carbon $date): string
    {
        return $date->format('M Y');
    }

    private function longMonthLabel(Carbon $date): string
    {
        return $date->format('F Y');
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
        if ($canManage && ($provider === PaymentProvider::PAKASIR
            || ($provider === PaymentProvider::KLIKQRIS && ! $this->klikQrisReadiness->checkout()->isReady())
            || ($provider === PaymentProvider::SAKURUPIAH && ! $this->sakurupiahReadiness->observe()->isReady()))) {
            $items[] = [
                'title' => 'Provider pembayaran perlu diperiksa',
                'detail' => $provider === PaymentProvider::PAKASIR
                    ? PaymentProvider::label($provider).' tidak lagi tersedia untuk dipilih. Ganti provider sebelum membuka pembayaran baru.'
                    : PaymentProvider::label($provider).' belum siap menerima pembayaran baru. Periksa konfigurasi provider.',
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
