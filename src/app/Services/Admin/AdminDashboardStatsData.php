<?php

namespace App\Services\Admin;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Enums\CustomerUsdtFundingRail;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\ManualPaymentSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\CheckoutMaintenance\CheckoutMaintenance;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\Sakurupiah\SakurupiahOperationalMetrics;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\StoreBalance\StoreBalanceFundingPolicyManager;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtFunding\CustomerUsdtFundingGate;
use App\Services\UsdtFunding\CustomerUsdtFundingPolicyManager;
use App\Support\Money;
use App\Support\PaymentProvider;
use Illuminate\Support\Carbon;

final class AdminDashboardStatsData
{
    public function __construct(
        private readonly KlikQrisProviderReadiness $klikQrisReadiness,
        private readonly SakurupiahProviderReadiness $sakurupiahReadiness,
        private readonly CheckoutMaintenance $maintenance,
        private readonly StoreBalanceFundingPolicyManager $fundingPolicies,
        private readonly BscDashboardData $bscDashboard,
        private readonly SakurupiahOperationalMetrics $sakurupiahMetrics,
        private readonly CustomerUsdtFundingPolicyManager $usdtFundingPolicies,
        private readonly CustomerUsdtFundingGate $usdtFundingGate,
        private readonly AdminBalanceOperationsData $balanceOperations,
        private readonly AdminDashboardOverviewData $overview,
    ) {}

    /**
     * @param  array<string, int>  $metrics
     * @param  array<string, string>  $storeLink
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function forStats(User $user, array $metrics, array $storeLink, array $filters = []): array
    {
        $header = $this->forCompactHeader($metrics['active_product_count'], $metrics['orders_to_process'], $storeLink);

        return $header + [
            'overview' => $this->overview->forAdmin($user, $metrics['orders_to_process'], $filters),
        ];
    }

    /** @return array<string, mixed> */
    public function forSettings(User $user, mixed $requestedSection = null): array
    {
        $section = in_array($requestedSection, ['pembayaran', 'saldo', 'diagnostik'], true) ? $requestedSection : 'pembayaran';

        return ['section' => $section] + match ($section) {
            'saldo' => [
                'fundingPolicy' => $this->fundingPolicy($user),
                'usdtFundingPolicy' => $this->usdtFundingPolicy($user),
            ],
            'diagnostik' => ['balanceOperations' => $this->balanceOperations->summary()],
            default => [
                'paymentControl' => $this->paymentControl($user),
                'checkoutMaintenance' => $this->checkoutMaintenance($user),
            ],
        };
    }

    /**
     * Build the shared internal-tab header without calculating dashboard
     * summary metrics that are only shown on the Stats tab.
     *
     * @param  array{label: string, url: string, display: string}  $storeLink
     * @return array<string, mixed>
     */
    public function forCompactHeader(int $activeProductCount, int $ordersToProcess, array $storeLink): array
    {
        return [
            'storeLink' => $storeLink,
            'storeStatus' => $this->storeStatus([
                'active_product_count' => $activeProductCount,
                'orders_to_process' => $ordersToProcess,
            ]),
            'checkoutMaintenance' => $this->checkoutMaintenance(),
            'actions' => [
                'shop' => url('/#products'),
                'share' => $storeLink['url'],
            ],
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

    private function usdtFundingPolicy(User $user): array
    {
        $state = $this->usdtFundingPolicies->currentOrNull();
        $pauseStates = $this->usdtFundingPolicies->pauses();

        $rails = [];
        foreach (CustomerUsdtFundingRail::cases() as $rail) {
            $availability = $this->usdtFundingGate->availability($rail);
            $rails[] = [
                'rail' => $rail->value,
                'label' => $rail->label(),
                'available' => $availability->available,
                'reason' => $availability->reason->label(),
                'detail' => $availability->detail,
            ];
        }

        $pauses = [];
        foreach (CustomerUsdtFundingPauseScope::cases() as $scope) {
            $pause = $pauseStates[$scope->value] ?? null;
            $pauses[] = [
                'scope' => $scope->value,
                'label' => $scope->label(),
                'paused' => $pause !== null,
                'reason' => $pause?->reason,
                'paused_at' => $pause?->pausedAt,
            ];
        }

        $feeMicro = $state?->applicationFeeMicro ?? 0;

        return [
            'configured' => $state !== null,
            'funding_enabled' => $state?->fundingEnabled ?? false,
            'confirmation_ttl_seconds' => $state?->confirmationTtlSeconds
                ?? CustomerUsdtFundingPolicy::DEFAULT_CONFIRMATION_TTL_SECONDS,
            'application_fee_input' => $feeMicro === 0 ? '0' : UsdtMicroAmount::fromUnits($feeMicro)->decimal(),
            'application_fee_display' => $feeMicro === 0 ? '0 USDT' : UsdtMicroAmount::fromUnits($feeMicro)->decimal().' USDT',
            'terms_version' => $state?->termsVersion,
            'terms_body' => $state === null ? '' : $this->usdtFundingPolicies->termsBody($state->termsVersion),
            'version' => $state?->version ?? 0,
            'update_url' => route('admin.store-balance.usdt-policy.update'),
            'rails' => $rails,
            'pauses' => $pauses,
            'can_manage' => (bool) $user->role?->canManageUsdtFundingPolicy(),
        ];
    }

    private function paymentControl(User $user): array
    {
        $settings = ManualPaymentSetting::current();
        $requestedMode = $settings?->paymentProvider() ?? PaymentProvider::MANUAL;
        $klikQrisReadiness = $this->klikQrisReadiness->checkout();
        $isKlikQrisConfigured = $klikQrisReadiness->isReady();
        $isKlikQrisRequested = $requestedMode === PaymentProvider::KLIKQRIS;
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
            $isKlikQrisRequested => $isKlikQrisConfigured ? 'KlikQRIS aktif' : 'KlikQRIS aktif · belum siap',
            default => 'Manual /payment aktif',
        };
        $hint = match (true) {
            $isPakasirRequested => 'Setting lama Pakasir dipertahankan tanpa fallback. Selesaikan cutover provider sebelum membuka checkout baru.',
            $isSakurupiahEnabled => 'Provider Sakurupiah untuk checkout baru. Payment lama tetap diproses berdasarkan provider yang tersimpan.',
            $isSakurupiahRequestedButUnavailable => 'Sakurupiah tetap menjadi provider aktif dan tidak dialihkan otomatis. Perbaiki readiness sebelum checkout baru dibuka.',
            $isKlikQrisRequested => $isKlikQrisConfigured
                ? 'Provider KlikQRIS untuk checkout baru. Payment lama tetap diproses berdasarkan provider yang tersimpan.'
                : 'KlikQRIS tetap menjadi provider aktif dan tidak dialihkan otomatis. Perbaiki readiness sebelum checkout baru dibuka.',
            default => 'Provider Manual: QR manual LHIE STORE, bukti /payment, dan approval admin.',
        };
        $statusBadge = match (true) {
            $isPakasirRequested => 'PAKASIR RETIRED',
            $isSakurupiahEnabled => 'SAKURUPIAH',
            $isSakurupiahRequestedButUnavailable => 'SAKURUPIAH BELUM SIAP',
            $isKlikQrisRequested => $isKlikQrisConfigured ? 'KLIKQRIS' : 'KLIKQRIS BELUM SIAP',
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
            'is_sakurupiah_enabled' => $isSakurupiahEnabled,
            'is_sakurupiah_configured' => $isSakurupiahConfigured,
            'can_enable_sakurupiah' => $isSakurupiahConfigured,
            'can_enable_klikqris' => $isKlikQrisConfigured,
            'klikqris_guard_message' => $isKlikQrisConfigured
                ? null
                : 'Provider KlikQRIS belum siap. '.$klikQrisReadiness->configurationMessage(),
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
            'tone' => $isPakasirRequested ? 'rose' : (($isSakurupiahEnabled || ($isKlikQrisRequested && $isKlikQrisConfigured)) ? 'purple' : 'amber'),
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
}
