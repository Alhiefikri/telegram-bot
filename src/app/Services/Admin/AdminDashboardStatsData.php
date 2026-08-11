<?php

namespace App\Services\Admin;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\PaymentReceiptReport;
use App\Services\Telegram\SalesProofPostNumbering;
use App\Support\Money;
use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Support\Carbon;

final class AdminDashboardStatsData
{
    public function __construct(
        private readonly PakasirProviderReadiness $pakasirReadiness,
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly SalesProofPostNumbering $salesProofNumbers,
        private readonly PaymentReceiptReport $receiptReport,
        private readonly CheckoutMaintenance $maintenance,
    ) {}

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, mixed>  $statusCounts
     * @param  array<string, string>  $storeLink
     * @return array<string, mixed>
     */
    public function forStats(User $user, array $metrics, array $statusCounts, array $storeLink): array
    {
        $header = $this->forHeader($metrics, $storeLink);
        $header['checkoutMaintenance'] = $this->checkoutMaintenance($user);

        return $header + [
            'balance' => $this->balance($metrics['read_only_balance_amount']),
            'cards' => $this->statsCards($metrics),
            'paymentControl' => $this->paymentControl($user),
            'statusCounts' => $statusCounts,
            'actionableStatusCounts' => $this->actionableStatusCounts($statusCounts),
            'earnings' => $this->earnings(),
        ];
    }

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $storeLink
     * @return array<string, mixed>
     */
    public function forHeader(array $metrics, array $storeLink): array
    {
        return [
            'storeLink' => $storeLink,
            'summary' => $this->summaryCards($metrics),
            'storeStatus' => $this->storeStatus($metrics),
            'checkoutMaintenance' => $this->checkoutMaintenance(),
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

    /** @return array<string, mixed> */
    private function checkoutMaintenance(?User $user = null): array
    {
        $state = $this->maintenance->current();
        $active = $state->active;

        return [
            'active' => $active,
            'label' => $active ? 'Checkout Maintenance aktif' : 'Checkout normal',
            'hint' => $active
                ? 'Order baru ditahan. Katalog, keranjang, dan Order yang sudah ada tetap berjalan.'
                : 'Customer dapat membuat Order baru seperti biasa.',
            'tone' => $active ? 'rose' : 'emerald',
            'status_badge' => [
                'label' => $active ? 'CHECKOUT DITAHAN' : 'CHECKOUT TERBUKA',
            ],
            'public_note' => $state->publicNote,
            'activated_at' => $state->activatedAt?->copy()->timezone((string) config('app.timezone'))->format('d M Y H:i'),
            'version' => $state->version,
            'update_url' => route('admin.checkout-maintenance.update'),
            'can_manage' => (bool) $user?->role?->canManageStoreOperations(),
        ];
    }

    private function paymentControl(User $user): array
    {
        $settings = ManualPaymentSetting::current();
        $requestedMode = $settings?->paymentProvider() ?? PaymentProvider::MANUAL;
        $requestedCheckoutFlow = (string) ($settings?->pakasir_checkout_flow ?: PakasirCheckoutFlow::HOSTED);
        $pakasirReadiness = $this->pakasirReadiness->checkout($requestedCheckoutFlow);
        $klikQrisReadiness = $this->klikQrisReadiness->checkout();
        $checkoutFlow = $pakasirReadiness->checkoutFlow();
        $isPakasirConfigured = $pakasirReadiness->isReady();
        $isKlikQrisConfigured = $klikQrisReadiness->isReady();
        $isPakasirEnabled = $requestedMode === PaymentProvider::PAKASIR && $isPakasirConfigured;
        $isKlikQrisEnabled = $requestedMode === PaymentProvider::KLIKQRIS && $isKlikQrisConfigured;
        $isPakasirRequestedButUnavailable = $requestedMode === PaymentProvider::PAKASIR && ! $isPakasirConfigured;
        $isKlikQrisRequestedButUnavailable = $requestedMode === PaymentProvider::KLIKQRIS && ! $isKlikQrisConfigured;
        $effectiveMode = $isPakasirRequestedButUnavailable ? PaymentProvider::MANUAL : $requestedMode;

        $label = match (true) {
            $isKlikQrisEnabled => 'KlikQRIS aktif',
            $isKlikQrisRequestedButUnavailable => 'KlikQRIS aktif — belum siap',
            $isPakasirRequestedButUnavailable => 'Manual /payment aktif — Pakasir belum siap',
            $isPakasirEnabled && $checkoutFlow === PakasirCheckoutFlow::API_QRIS => 'Pakasir native QRIS aktif',
            $isPakasirEnabled => 'Pakasir hosted aktif',
            default => 'Manual /payment aktif',
        };
        $hint = match (true) {
            $isKlikQrisEnabled => 'Provider KlikQRIS untuk checkout baru. Payment lama tetap diproses berdasarkan provider yang tersimpan.',
            $isKlikQrisRequestedButUnavailable => 'KlikQRIS tetap menjadi provider aktif dan tidak dialihkan otomatis. Perbaiki readiness atau rollback eksplisit ke provider lain.',
            $isPakasirRequestedButUnavailable => 'Mode tersimpan meminta Pakasir, tetapi checkout baru ditahan sampai readiness Pakasir pulih.',
            $isPakasirEnabled && $checkoutFlow === PakasirCheckoutFlow::API_QRIS => 'Provider Pakasir native QRIS. Paid tetap diverifikasi melalui status provider dan payment lama tidak berubah.',
            $isPakasirEnabled => 'Provider Pakasir hosted. Paid tetap diverifikasi melalui status provider dan payment lama tidak berubah.',
            default => 'Provider Manual: QR manual LHIE STORE, bukti /payment, dan approval admin.',
        };
        $statusBadge = match (true) {
            $isKlikQrisEnabled => 'KLIKQRIS',
            $isKlikQrisRequestedButUnavailable => 'KLIKQRIS BELUM SIAP',
            $isPakasirRequestedButUnavailable => 'PAKASIR BELUM SIAP',
            $isPakasirEnabled && $checkoutFlow === PakasirCheckoutFlow::API_QRIS => 'NATIVE QRIS',
            $isPakasirEnabled => 'HOSTED',
            default => 'MANUAL',
        };
        $providers = [
            PaymentProvider::MANUAL => [
                'label' => PaymentProvider::label(PaymentProvider::MANUAL),
                'ready' => true,
                'reason' => 'Jalur darurat selalu tersedia.',
                'can_activate' => true,
            ],
            PaymentProvider::PAKASIR => [
                'label' => PaymentProvider::label(PaymentProvider::PAKASIR),
                'ready' => $isPakasirConfigured,
                'reason' => $isPakasirConfigured ? null : $pakasirReadiness->configurationMessage(),
                'can_activate' => $isPakasirConfigured,
            ],
            PaymentProvider::KLIKQRIS => [
                'label' => PaymentProvider::label(PaymentProvider::KLIKQRIS),
                'ready' => $isKlikQrisConfigured,
                'reason' => $isKlikQrisConfigured ? null : $klikQrisReadiness->configurationMessage(),
                'can_activate' => $isKlikQrisConfigured,
            ],
        ];
        $pending = $this->pendingPayments();
        $requestedProviderLabel = PaymentProvider::label($requestedMode);
        $effectiveProviderLabel = PaymentProvider::label($effectiveMode);

        return [
            'mode' => $effectiveMode,
            'effective_mode' => $effectiveMode,
            'requested_mode' => $requestedMode,
            'requested_provider' => $requestedMode,
            'effective_provider' => $effectiveMode,
            'requested_provider_label' => $requestedProviderLabel,
            'effective_provider_label' => $effectiveProviderLabel,
            'active_provider_label' => $requestedProviderLabel,
            'payment_provider_version' => (int) ($settings?->payment_provider_version ?? 0),
            'checkout_flow' => $checkoutFlow,
            'requested_checkout_flow' => $requestedCheckoutFlow,
            'checkout_flow_label' => $checkoutFlow === PakasirCheckoutFlow::API_QRIS ? 'native QRIS' : 'hosted',
            'is_pakasir_enabled' => $isPakasirEnabled,
            'is_pakasir_configured' => $isPakasirConfigured,
            'can_enable_pakasir' => $isPakasirConfigured,
            'is_klikqris_enabled' => $isKlikQrisEnabled,
            'is_klikqris_configured' => $isKlikQrisConfigured,
            'can_enable_klikqris' => $isKlikQrisConfigured,
            'pakasir_config_missing' => $pakasirReadiness->missing(),
            'pakasir_config_invalid' => array_keys($pakasirReadiness->invalid()),
            'pakasir_guard_message' => $isPakasirConfigured
                ? null
                : 'Provider Pakasir belum siap. '.$pakasirReadiness->configurationMessage(),
            'klikqris_config_missing' => $klikQrisReadiness->missing(),
            'klikqris_config_invalid' => array_keys($klikQrisReadiness->invalid()),
            'klikqris_guard_message' => $isKlikQrisConfigured
                ? null
                : 'Provider KlikQRIS belum siap. '.$klikQrisReadiness->configurationMessage(),
            'providers' => $providers,
            'pending_by_provider' => $pending['counts'],
            'oldest_pending_by_provider' => $pending['oldest'],
            'switch_audit' => $this->switchAudits(),
            'pakasir_cutoff' => $this->pakasirCutoff(),
            'label' => $label,
            'hint' => $hint,
            'tone' => $isKlikQrisEnabled ? 'purple' : ($isPakasirEnabled ? ($checkoutFlow === PakasirCheckoutFlow::API_QRIS ? 'purple' : 'emerald') : 'amber'),
            'source' => $settings ? 'dashboard_setting' : 'dashboard_default',
            'can_manage' => (bool) $user->role?->canManagePaymentSettings(),
            'can_switch_provider' => (bool) $user->role?->canSwitchPaymentProvider(),
            'can_change_payment_mode' => (bool) $user->role?->canSwitchPaymentProvider(),
            'provider_switch_guard_message' => $user->role?->canSwitchPaymentProvider()
                ? null
                : 'Hanya owner yang bisa mengganti provider aktif.',
            'binance_pay_enabled' => (bool) $settings?->binance_pay_enabled,
            'binance_verification_mode' => in_array($settings?->binance_verification_mode, ['amount_unique', 'order_id'], true)
                ? $settings->binance_verification_mode
                : 'amount_unique',
            'qris_payment_ttl_minutes' => $settings?->qrisPaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_QRIS_PAYMENT_TTL_MINUTES,
            'binance_payment_ttl_minutes' => $settings?->binancePaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES,
            'whatsapp_support_url' => $settings?->whatsapp_support_url,
            'whatsapp_support_number' => $settings?->whatsappSupportNumber(),
            'whatsapp_support_effective_url' => $settings?->whatsappSupportUrl() ?: $this->whatsappSupportFallbackUrl(),
            'whatsapp_support_source' => $settings?->whatsappSupportUrl() ? 'dashboard_setting' : 'environment_fallback',
            'update_url' => route('admin.payment-mode.update'),
            'support_update_url' => route('admin.support-settings.update'),
            'status_badge' => [
                'label' => $statusBadge,
            ],
        ];
    }

    /** @return array{counts: array<string, int>, oldest: array<string, ?string>} */
    private function pendingPayments(): array
    {
        $counts = [];
        $oldest = [];
        foreach (PaymentProvider::allowed() as $provider) {
            $query = Payment::query()
                ->where('provider', $provider)
                ->where('status', PaymentStatus::Pending);
            $counts[$provider] = (clone $query)->count();
            $oldestCreatedAt = (clone $query)->oldest('created_at')->value('created_at');
            $oldest[$provider] = $oldestCreatedAt === null
                ? null
                : Carbon::parse((string) $oldestCreatedAt)->timezone((string) config('app.timezone'))->format('d M Y H:i');
        }

        return ['counts' => $counts, 'oldest' => $oldest];
    }

    /** @return list<array{actor: string, at: ?string, old_provider: string, new_provider: string}> */
    private function switchAudits(): array
    {
        return AuditLog::query()
            ->with('user')
            ->where('action', 'payment_provider.switched')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(static function (AuditLog $audit): array {
                $oldProvider = PaymentProvider::normalize($audit->old_values['provider'] ?? 'manual');
                $newProvider = PaymentProvider::normalize($audit->new_values['provider'] ?? 'manual');

                return [
                    'actor' => (string) ($audit->user?->name ?: $audit->user?->email ?: 'Unknown'),
                    'at' => $audit->created_at?->toIso8601String(),
                    'old_provider' => PaymentProvider::label($oldProvider),
                    'new_provider' => PaymentProvider::label($newProvider),
                ];
            })
            ->all();
    }

    /** @return array{date: string, is_past: bool, hours_remaining: int, days_remaining: int, deadline_at: string, message: string} */
    private function pakasirCutoff(): array
    {
        $cutoff = Carbon::create(2026, 8, 15, 0, 0, 0, (string) config('app.timezone'));
        $now = now();
        $isPast = $now->greaterThanOrEqualTo($cutoff);
        $minutesRemaining = $isPast ? 0 : (int) ceil($now->diffInSeconds($cutoff, false) / 60);
        $hoursRemaining = $isPast ? 0 : (int) ceil($minutesRemaining / 60);

        return [
            'date' => $cutoff->toDateString(),
            'is_past' => $isPast,
            'hours_remaining' => $hoursRemaining,
            'days_remaining' => $isPast ? 0 : (int) ceil($minutesRemaining / 1440),
            'deadline_at' => $cutoff->toIso8601String(),
            'message' => $isPast
                ? 'Batas operasional Pakasir telah lewat. Matikan Pakasir secara manual setelah payment lama terminal.'
                : 'Pakasir akan dihentikan manual sebelum 15 Agustus 2026; tidak ada cutoff otomatis.',
        ];
    }

    private function whatsappSupportFallbackUrl(): ?string
    {
        $url = trim((string) config('services.support.whatsapp_url'));
        if ($url !== '') {
            return $url;
        }

        $number = ManualPaymentSetting::normalizeWhatsappNumber((string) config('services.support.whatsapp_number'));

        return $number !== '' ? 'https://wa.me/'.$number : null;
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
        $payments = $this->receiptReport->canonicalPaidPayments($start);

        $amountsByDate = $payments
            ->filter(fn (Payment $payment): bool => $this->receiptReport->currency($payment) === 'IDR')
            ->groupBy(fn (Payment $payment): string => $payment->paid_at->toDateString())
            ->map(fn ($payments): int => (int) $payments->sum(fn (Payment $payment): int => $this->receiptReport->netAmount($payment)));
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
