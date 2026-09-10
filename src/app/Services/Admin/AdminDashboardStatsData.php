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
use App\Services\PaymentReceiptReport;
use App\Services\Sakurupiah\SakurupiahOperationalMetrics;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\Telegram\SalesProofPostNumbering;
use App\Support\Money;
use App\Support\PaymentProvider;
use Illuminate\Support\Carbon;

final class AdminDashboardStatsData
{
    public function __construct(
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly SalesProofPostNumbering $salesProofNumbers,
        private readonly PaymentReceiptReport $receiptReport,
        private readonly CheckoutMaintenance $maintenance,
        private readonly StoreBalanceFundingPolicyManager $fundingPolicies,
        private readonly BscDashboardData $bscDashboard,
        private readonly SakurupiahOperationalMetrics $sakurupiahMetrics,
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
            'fundingPolicy' => $this->fundingPolicy($user),
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

    /** @return array<string, mixed> */
    private function fundingPolicy(User $user): array
    {
        $state = $this->fundingPolicies->current();

        return [
            'minimum_amount' => $state->minimumAmount,
            'minimum_formatted' => Money::idr($state->minimumAmount),
            'preset_amounts' => $state->presetAmounts,
            'preset_amounts_formatted' => array_map(
                static fn (int $amount): string => Money::idr($amount),
                $state->presetAmounts,
            ),
            'preset_amounts_input' => implode(
                "\n",
                array_map(static fn (int $amount): string => (string) $amount, $state->presetAmounts),
            ),
            'version' => $state->version,
            'update_url' => route('admin.store-balance.funding-policy.update'),
            'can_manage' => (bool) $user->role?->canManageStoreOperations(),
        ];
    }

    private function paymentControl(User $user): array
    {
        $settings = ManualPaymentSetting::current();
        $requestedMode = $settings?->paymentProvider() ?? PaymentProvider::MANUAL;
        $klikQrisReadiness = $this->klikQrisReadiness->checkout();
        $isKlikQrisConfigured = $klikQrisReadiness->isReady();
        $isKlikQrisEnabled = $requestedMode === PaymentProvider::KLIKQRIS && $isKlikQrisConfigured;
        $isKlikQrisRequestedButUnavailable = $requestedMode === PaymentProvider::KLIKQRIS && ! $isKlikQrisConfigured;
        $sakurupiahReadiness = $this->sakurupiahReadiness->observe();
        $isSakurupiahConfigured = $sakurupiahReadiness->isReady();
        $isSakurupiahEnabled = $requestedMode === PaymentProvider::SAKURUPIAH && $isSakurupiahConfigured;
        $isSakurupiahRequestedButUnavailable = $requestedMode === PaymentProvider::SAKURUPIAH && ! $isSakurupiahConfigured;
        $isPakasirRequested = $requestedMode === PaymentProvider::PAKASIR;
        $effectiveMode = $requestedMode;

        $label = match (true) {
            $isPakasirRequested => 'Pakasir retired — checkout blocked',
            $isSakurupiahEnabled => 'Sakurupiah aktif',
            $isSakurupiahRequestedButUnavailable => 'Sakurupiah aktif — belum siap',
            $isKlikQrisEnabled => 'KlikQRIS aktif',
            $isKlikQrisRequestedButUnavailable => 'KlikQRIS aktif — belum siap',
            default => 'Manual /payment aktif',
        };
        $hint = match (true) {
            $isPakasirRequested => 'Setting lama Pakasir dipertahankan tanpa fallback. Selesaikan cutover provider sebelum membuka checkout baru.',
            $isSakurupiahEnabled => 'Provider Sakurupiah untuk checkout baru. Payment lama tetap diproses berdasarkan provider yang tersimpan.',
            $isSakurupiahRequestedButUnavailable => 'Sakurupiah tetap menjadi provider aktif dan tidak dialihkan otomatis. Perbaiki readiness sebelum checkout baru dibuka.',
            $isKlikQrisEnabled => 'Provider KlikQRIS untuk checkout baru. Payment lama tetap diproses berdasarkan provider yang tersimpan.',
            $isKlikQrisRequestedButUnavailable => 'KlikQRIS tetap menjadi provider aktif dan tidak dialihkan otomatis. Perbaiki readiness sebelum checkout baru dibuka.',
            default => 'Provider Manual: QR manual LHIE STORE, bukti /payment, dan approval admin.',
        };
        $statusBadge = match (true) {
            $isPakasirRequested => 'PAKASIR RETIRED',
            $isSakurupiahEnabled => 'SAKURUPIAH',
            $isSakurupiahRequestedButUnavailable => 'SAKURUPIAH BELUM SIAP',
            $isKlikQrisEnabled => 'KLIKQRIS',
            $isKlikQrisRequestedButUnavailable => 'KLIKQRIS BELUM SIAP',
            default => 'MANUAL',
        };
        $providers = [
            PaymentProvider::MANUAL => [
                'label' => PaymentProvider::label(PaymentProvider::MANUAL),
                'ready' => true,
                'reason' => 'Jalur darurat selalu tersedia.',
                'can_activate' => true,
            ],
            PaymentProvider::KLIKQRIS => [
                'label' => PaymentProvider::label(PaymentProvider::KLIKQRIS),
                'ready' => $isKlikQrisConfigured,
                'reason' => $isKlikQrisConfigured ? null : $klikQrisReadiness->configurationMessage(),
                'can_activate' => $isKlikQrisConfigured,
            ],
            PaymentProvider::SAKURUPIAH => [
                'label' => PaymentProvider::label(PaymentProvider::SAKURUPIAH),
                'ready' => $isSakurupiahConfigured,
                'reason' => $isSakurupiahConfigured ? null : $sakurupiahReadiness->configurationMessage(),
                'can_activate' => $isSakurupiahConfigured,
            ],
        ];
        $pending = $this->pendingPayments();
        $sakurupiahBacklog = $this->sakurupiahMetrics->pendingVerification();
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
            'is_klikqris_enabled' => $isKlikQrisEnabled,
            'is_klikqris_configured' => $isKlikQrisConfigured,
            'can_enable_klikqris' => $isKlikQrisConfigured,
            'klikqris_config_missing' => $klikQrisReadiness->missing(),
            'klikqris_config_invalid' => array_keys($klikQrisReadiness->invalid()),
            'klikqris_guard_message' => $isKlikQrisConfigured
                ? null
                : 'Provider KlikQRIS belum siap. '.$klikQrisReadiness->configurationMessage(),
            'is_sakurupiah_enabled' => $isSakurupiahEnabled,
            'is_sakurupiah_configured' => $isSakurupiahConfigured,
            'can_enable_sakurupiah' => $isSakurupiahConfigured,
            'sakurupiah_config_missing' => $sakurupiahReadiness->missing(),
            'sakurupiah_config_invalid' => array_keys($sakurupiahReadiness->invalid()),
            'sakurupiah_guard_message' => $isSakurupiahConfigured
                ? null
                : 'Provider Sakurupiah belum siap. '.$sakurupiahReadiness->configurationMessage(),
            'providers' => $providers,
            'pending_by_provider' => $pending['counts'],
            'oldest_pending_by_provider' => $pending['oldest'],
            'sakurupiah_backlog' => $sakurupiahBacklog,
            'switch_audit' => $this->switchAudits(),
            'label' => $label,
            'hint' => $hint,
            'tone' => $isPakasirRequested ? 'rose' : (($isSakurupiahEnabled || $isKlikQrisEnabled) ? 'purple' : 'amber'),
            'is_pakasir_retired' => $isPakasirRequested,
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
            'bsc' => $this->bscDashboard->forAdmin($user),
        ];
    }

    /** @return array{counts: array<string, int>, oldest: array<string, ?string>} */
    private function pendingPayments(): array
    {
        $counts = [];
        $oldest = [];
        foreach (PaymentProvider::known() as $provider) {
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
