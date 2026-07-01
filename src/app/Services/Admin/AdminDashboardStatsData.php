<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\SalesProofPost;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;

final class AdminDashboardStatsData
{
    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, mixed>  $statusCounts
     * @param  array<string, string>  $storeLink
     * @return array<string, mixed>
     */
    public function forStats(User $user, array $metrics, array $statusCounts, array $storeLink): array
    {
        return [
            'balance' => $this->balance($metrics['read_only_balance_amount']),
            'storeLink' => $storeLink,
            'cards' => $this->statsCards($metrics),
            'summary' => $this->summaryCards($metrics),
            'storeStatus' => $this->storeStatus($metrics),
            'paymentControl' => $this->paymentControl($user),
            'statusCounts' => $statusCounts,
            'actionableStatusCounts' => $this->actionableStatusCounts($statusCounts),
            'earnings' => $this->earnings(),
            'actions' => [
                'shop' => url('/#products'),
                'share' => $storeLink['url'],
            ],
        ];
    }

    private function balance(int $readOnlyBalanceAmount): array
    {
        return [
            'label' => 'Saldo estimasi',
            'amount' => $readOnlyBalanceAmount,
            'amount_formatted' => Money::idr($readOnlyBalanceAmount),
            'source' => 'paid_payments',
        ];
    }

    private function statsCards(array $metrics): array
    {
        return [
            $this->metricCard('gmv', 'GMV', Money::idr($metrics['gmv_amount']), 'Order aktif + selesai', 'blue'),
            $this->metricCard('saldo', 'Saldo', Money::idr($metrics['read_only_balance_amount']), 'Estimasi pembayaran paid', 'emerald'),
            $this->metricCard('orders', 'Order', $metrics['order_count'], 'Total pesanan', 'amber'),
            $this->metricCard('sales_proof_posts', 'Proof post', SalesProofPost::query()->where('status', SalesProofPost::STATUS_SENT)->count(), 'Terkirim ke channel', 'emerald'),
            $this->metricCard('active_products', 'Produk aktif', $metrics['active_product_count'], 'Tampil di toko', 'emerald'),
        ];
    }

    private function summaryCards(array $metrics): array
    {
        return [
            $this->metricCard('saldo', 'Saldo', Money::idr($metrics['read_only_balance_amount']), 'Estimasi pembayaran paid', 'emerald'),
            $this->metricCard('gmv', 'GMV', Money::idr($metrics['gmv_amount']), 'Order aktif + selesai', 'blue'),
            $this->metricCard('products', 'Produk', $metrics['product_count'], 'Total katalog', 'slate'),
            $this->metricCard('active_products', 'Produk aktif', $metrics['active_product_count'], 'Tampil di toko', 'emerald'),
            $this->metricCard('orders_to_process', 'Perlu diproses', $metrics['orders_to_process'], 'Order dibayar', 'amber'),
        ];
    }

    private function metricCard(string $key, string $label, int|string $value, string $hint, string $tone): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'hint' => $hint,
            'tone' => $tone,
            'card_class' => $this->toneCardClass($tone),
        ];
    }

    private function storeStatus(array $metrics): array
    {
        $activeProductCount = $metrics['active_product_count'];
        $ordersToProcess = $metrics['orders_to_process'];

        return [
            'label' => $activeProductCount > 0 ? 'Toko aktif' : 'Toko belum aktif',
            'hint' => $ordersToProcess > 0 ? 'Ada order perlu tindak lanjut' : 'Tidak ada antrean proses',
            'tone' => $ordersToProcess > 0 ? 'amber' : ($activeProductCount > 0 ? 'emerald' : 'slate'),
        ];
    }

    private function paymentControl(User $user): array
    {
        $settings = ManualPaymentSetting::current();
        $mode = (string) ($settings?->payment_mode ?: config('services.telegram.payment_mode', 'manual'));
        $mode = $mode === 'pakasir' ? 'pakasir' : 'manual';
        $isPakasirEnabled = $mode === 'pakasir';

        return [
            'mode' => $mode,
            'is_pakasir_enabled' => $isPakasirEnabled,
            'label' => $isPakasirEnabled ? 'Pakasir aktif' : 'QR Manual aktif',
            'hint' => $isPakasirEnabled
                ? 'Checkout memakai QRIS/hosted Pakasir dan auto-verifikasi.'
                : 'Checkout memakai QR manual LHIE STORE, bukti /payment, dan approval admin.',
            'tone' => $isPakasirEnabled ? 'emerald' : 'amber',
            'source' => $settings ? 'dashboard_setting' : 'env_default',
            'can_manage' => (bool) $user->role?->canManagePaymentSettings(),
            'update_url' => route('admin.payment-mode.update'),
            'status_badge' => [
                'label' => $isPakasirEnabled ? 'AKTIF' : 'NONAKTIF',
                'background' => $isPakasirEnabled ? '#e8ffd4' : '#fce4f0',
                'dot' => $isPakasirEnabled ? '#7BD10A' : '#ef4444',
            ],
        ];
    }

    private function actionableStatusCounts(array $statusCounts): array
    {
        return collect([
            OrderStatus::PendingPayment,
            OrderStatus::Processing,
            OrderStatus::Cancelled,
            OrderStatus::Expired,
        ])
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $statusCounts[$status->value]])
            ->all();
    }

    private function earnings(): array
    {
        $start = now()->startOfDay()->subDays(6);
        $payments = Payment::query()
            ->where('status', PaymentStatus::Paid)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $start)
            ->get(['amount', 'paid_at']);

        $amountsByDate = $payments
            ->groupBy(fn (Payment $payment): string => $payment->paid_at->toDateString())
            ->map(fn ($payments): int => (int) $payments->sum('amount'));
        $maxAmount = max(1, (int) $amountsByDate->max());

        return collect(range(0, 6))
            ->map(function (int $dayOffset) use ($start, $amountsByDate, $maxAmount): array {
                $date = (clone $start)->addDays($dayOffset);
                $amount = (int) ($amountsByDate[$date->toDateString()] ?? 0);

                return [
                    'date' => $date->toDateString(),
                    'label' => Carbon::parse($date)->format('d M'),
                    'amount' => $amount,
                    'amount_formatted' => Money::idr($amount),
                    'bar_percent' => $amount > 0 ? max(8, (int) round(($amount / $maxAmount) * 100)) : 0,
                ];
            })
            ->all();
    }

    private function toneCardClass(string $tone): string
    {
        return match ($tone) {
            'emerald' => 'stat-card-green',
            'blue' => 'stat-card-cyan',
            'amber' => 'stat-card-yellow',
            'rose' => 'stat-card-pink',
            default => 'stat-card-purple',
        };
    }
}
