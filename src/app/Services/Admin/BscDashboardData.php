<?php

namespace App\Services\Admin;

use App\Models\BscPaymentReceiver;
use App\Models\ManualPaymentSetting;
use App\Models\User;
use App\Services\Bsc\BscReadiness;
use Illuminate\Database\Eloquent\Collection;

final class BscDashboardData
{
    public function __construct(private readonly BscReadiness $readiness) {}

    /** @return array<string, mixed> */
    public function forAdmin(User $user): array
    {
        $setting = ManualPaymentSetting::current();
        $report = $this->readiness->check($setting);
        $receivers = $this->receivers();

        return [
            'enabled' => (bool) $setting?->bsc_payment_enabled,
            'ttl_minutes' => $setting?->bscPaymentTtlMinutes() ?? ManualPaymentSetting::DEFAULT_BSC_PAYMENT_TTL_MINUTES,
            'min_confirmations' => $setting?->bscMinConfirmations() ?? ManualPaymentSetting::DEFAULT_BSC_MIN_CONFIRMATIONS,
            'require_finalized_block' => (bool) $setting?->bsc_require_finalized_block,
            'confirmation_grace_minutes' => $setting?->bscConfirmationGraceMinutes() ?? ManualPaymentSetting::DEFAULT_BSC_CONFIRMATION_GRACE_MINUTES,
            'hash_attempt_limit' => $setting?->bscHashAttemptLimit() ?? ManualPaymentSetting::DEFAULT_BSC_HASH_ATTEMPT_LIMIT,
            'readiness' => [
                'ready' => $report->isReady(),
                'label' => $report->isReady()
                    ? 'Siap (TX Hash)'
                    : ($report->enabled ? 'Belum siap' : 'Nonaktif'),
                'message' => $report->configurationMessage(),
                'missing' => $report->missing,
                'invalid' => array_values($report->invalid),
                'active_receiver_count' => $report->activeReceiverCount,
                'rpc_chain_id' => $report->rpcChainId,
                'token_decimals' => $report->tokenDecimals,
                'checked_at' => $report->checkedAt?->toIso8601String(),
            ],
            'receivers' => $receivers,
            'can_manage_settings' => (bool) $user->role?->canManagePaymentSettings(),
            'can_manage_receivers' => (bool) $user->role?->canManageBscReceivers(),
            'settings_url' => route('admin.payment-mode.update'),
            'add_url' => route('admin.bsc.receivers.store'),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function receivers(): array
    {
        try {
            /** @var Collection<int, BscPaymentReceiver> $receivers */
            $receivers = BscPaymentReceiver::query()
                ->latest('id')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $receivers->map(function (BscPaymentReceiver $receiver): array {
            return [
                'id' => (int) $receiver->getKey(),
                'label' => (string) $receiver->label,
                'address' => (string) $receiver->address_normalized,
                'masked_address' => $receiver->maskedAddress(),
                'is_active' => (bool) $receiver->is_active,
                'verification_status' => $receiver->verification_status?->value ?? 'pending_verification',
                'verification_label' => $receiver->verification_status?->label() ?? 'Menunggu verifikasi wallet',
                'verification_method' => $receiver->verification_method,
                'manual_verify_url' => route('admin.bsc.receivers.manual-verify', $receiver),
                'activate_url' => route('admin.bsc.receivers.activate', $receiver),
                'deactivate_url' => route('admin.bsc.receivers.deactivate', $receiver),
            ];
        })->values()->all();
    }
}
