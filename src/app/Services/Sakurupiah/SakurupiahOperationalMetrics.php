<?php

namespace App\Services\Sakurupiah;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\PaymentProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Bounded local metrics for Sakurupiah reconciliation and admin visibility.
 *
 * These queries never call the provider. They only count the local Payment
 * backlog that scheduled or manual reconciliation is responsible for, so the
 * admin surfaces can surface "verification pending" without per-row provider
 * requests.
 */
final class SakurupiahOperationalMetrics
{
    public function __construct(
        private readonly ?int $terminalLookbackHours = null,
    ) {}

    /**
     * Local Payments that still need an authoritative provider observation.
     *
     * The predicate matches the bounded reconciliation scan: only Payments
     * that carry a persisted provider reference (pending or recently released
     * terminal), or an unresolved create/recovery state. Payments that never
     * attempted creation belong to the post-commit create worker and are not
     * part of the reconciliation backlog.
     *
     * @return array{pending: int, verification_pending: int, terminal_recent: int, oldest_age_seconds: int, oldest_created_at: ?string}
     */
    public function pendingVerification(): array
    {
        try {
            $releasedCutoff = $this->releasedCutoff();
            $base = $this->reconcilableQuery($releasedCutoff);
            $pending = (clone $base)
                ->where('status', PaymentStatus::Pending->value)
                ->count();
            $verificationPending = (clone $base)
                ->where('status', PaymentStatus::Pending->value)
                ->whereIn('provider_create_state', [
                    SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
                    SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                ])
                ->count();
            $terminalRecent = (clone $base)
                ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                ->count();
            $oldest = (clone $base)
                ->where('status', PaymentStatus::Pending->value)
                ->oldest('created_at')
                ->value('created_at');
        } catch (\Throwable) {
            return [
                'pending' => 0,
                'verification_pending' => 0,
                'terminal_recent' => 0,
                'oldest_age_seconds' => 0,
                'oldest_created_at' => null,
            ];
        }

        $oldestTimestamp = $oldest === null ? null : strtotime((string) $oldest);

        return [
            'pending' => $pending,
            'verification_pending' => $verificationPending,
            'terminal_recent' => $terminalRecent,
            'oldest_age_seconds' => $oldestTimestamp === false || $oldestTimestamp === null
                ? 0
                : max(0, now()->timestamp - $oldestTimestamp),
            'oldest_created_at' => $oldest === null ? null : (string) $oldest,
        ];
    }

    private function reconcilableQuery(Carbon $releasedCutoff): Builder
    {
        return Payment::query()
            ->where('provider', PaymentProvider::SAKURUPIAH)
            ->whereNotNull('merchant_ref')
            ->where('method', 'QRIS')
            ->where('currency', 'IDR')
            ->where(function (Builder $query) use ($releasedCutoff): void {
                $query
                    ->where(function (Builder $recoverable) use ($releasedCutoff): void {
                        $recoverable
                            ->whereNotNull('provider_reference')
                            ->where(function (Builder $status) use ($releasedCutoff): void {
                                $status
                                    ->where('status', PaymentStatus::Pending->value)
                                    ->orWhere(fn (Builder $terminal) => $terminal
                                        ->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])
                                        ->where('updated_at', '>=', $releasedCutoff));
                            });
                    })
                    ->orWhereIn('provider_create_state', [
                        SakurupiahInvoiceLifecycleService::STATE_OUTCOME_UNKNOWN,
                        SakurupiahInvoiceLifecycleService::STATE_RECOVERY_UNCONFIRMED,
                    ]);
            });
    }

    private function releasedCutoff(): Carbon
    {
        $hours = $this->terminalLookbackHours ?? max(
            1,
            (int) config('services.sakurupiah.reconcile_terminal_lookback_hours', 72),
        );

        return now()->subHours(max(1, $hours));
    }
}
