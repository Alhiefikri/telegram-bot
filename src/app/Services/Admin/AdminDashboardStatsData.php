<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\Telegram\SalesProofPostNumbering;
use App\Support\Money;
use App\Support\PakasirCheckoutFlow;
use Illuminate\Support\Carbon;

final class AdminDashboardStatsData
{
    public function __construct(private readonly PakasirProviderReadiness $pakasirReadiness, private readonly SalesProofPostNumbering $salesProofNumbers) {}

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
            $this->metricCard('sales_proof_posts', 'Proof post', $this->salesProofNumbers->sentCount(), 'Terkirim ke channel', 'emerald'),
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
            $this->metricCard('completed_orders', 'Pesanan', $metrics['completed_order_count'], 'Order selesai', 'emerald'),
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
        $requestedMode = (string) ($settings?->payment_mode ?: 'manual');
        $requestedMode = $requestedMode === 'pakasir' ? 'pakasir' : 'manual';
        $requestedCheckoutFlow = (string) ($settings?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
        $readiness = $this->pakasirReadiness->checkout($requestedCheckoutFlow);
        $checkoutFlow = $readiness->checkoutFlow();
        $isPakasirConfigured = $readiness->isReady();
        $isPakasirEnabled = $requestedMode === 'pakasir' && $isPakasirConfigured;
        $isPakasirRequestedButUnavailable = $requestedMode === 'pakasir' && ! $isPakasirConfigured;

        $pakasirFlowLabel = $checkoutFlow === PakasirCheckoutFlow::API_QRIS ? 'native QRIS' : 'hosted';
        $label = match (true) {
            $isPakasirRequestedButUnavailable => 'Manual /payment aktif — Pakasir belum siap',
            ! $isPakasirEnabled => 'Manual /payment aktif',
            $checkoutFlow === PakasirCheckoutFlow::API_QRIS => 'Pakasir native QRIS aktif',
            default => 'Pakasir hosted aktif',
        };
        $hint = match (true) {
            $isPakasirRequestedButUnavailable => 'Mode tersimpan meminta Pakasir, tapi checkout baru dialihkan ke QR manual sampai konfigurasi Pakasir siap.',
            ! $isPakasirEnabled => 'Provider manual: QR manual LHIE STORE, bukti /payment, dan approval admin.',
            $checkoutFlow === PakasirCheckoutFlow::API_QRIS => 'Provider Pakasir / native QRIS: QR payment_number dikirim di Telegram, tombol hosted tetap jadi fallback, dan paid tetap diverifikasi via Transaction Detail.',
            default => 'Provider Pakasir / hosted: customer memakai QR/link website Pakasir, dan paid tetap diverifikasi via Transaction Detail.',
        };
        $statusBadge = match (true) {
            $isPakasirRequestedButUnavailable => 'PAKASIR BELUM SIAP',
            ! $isPakasirEnabled => 'MANUAL',
            $checkoutFlow === PakasirCheckoutFlow::API_QRIS => 'NATIVE QRIS',
            default => 'HOSTED',
        };
        $canEnablePakasir = $isPakasirConfigured;

        return [
            'mode' => $isPakasirEnabled ? 'pakasir' : 'manual',
            'requested_mode' => $requestedMode,
            'checkout_flow' => $checkoutFlow,
            'requested_checkout_flow' => $requestedCheckoutFlow,
            'checkout_flow_label' => $pakasirFlowLabel,
            'is_pakasir_enabled' => $isPakasirEnabled,
            'is_pakasir_configured' => $isPakasirConfigured,
            'can_enable_pakasir' => $canEnablePakasir,
            'pakasir_config_missing' => $readiness->missing(),
            'pakasir_config_invalid' => array_keys($readiness->invalid()),
            'pakasir_guard_message' => $canEnablePakasir
                ? null
                : 'Mode Pakasir belum siap. '.$readiness->configurationMessage().' Checkout baru akan memakai QR manual sampai diperbaiki.',
            'label' => $label,
            'hint' => $hint,
            'tone' => $isPakasirEnabled ? ($checkoutFlow === PakasirCheckoutFlow::API_QRIS ? 'purple' : 'emerald') : 'amber',
            'source' => $settings ? 'dashboard_setting' : 'dashboard_default',
            'can_manage' => (bool) $user->role?->canManagePaymentSettings(),
            'binance_pay_enabled' => (bool) $settings?->binance_pay_enabled,
            'update_url' => route('admin.payment-mode.update'),
            'status_badge' => [
                'label' => $statusBadge,
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
}
