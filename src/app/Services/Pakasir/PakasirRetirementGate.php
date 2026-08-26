<?php

namespace App\Services\Pakasir;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\PakasirRetirementApproval;
use App\Models\Payment;
use App\Models\ProviderCancellationAttempt;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;

final class PakasirRetirementGate
{
    private const TERMINAL_PAYMENT_STATUSES = [
        PaymentStatus::Paid->value,
        PaymentStatus::Failed->value,
        PaymentStatus::Expired->value,
        PaymentStatus::Refunded->value,
    ];

    /**
     * @return array{
     *     provider: string,
     *     gate: string,
     *     settlement_ready: bool,
     *     queue_safe: bool,
     *     retirement_ready: bool,
     *     owner_approval_required: bool,
     *     owner_approved: bool,
     *     approved_by: ?int,
     *     approved_at: ?string,
     *     non_terminal_payments: int,
     *     dispatchable_cancellation_work: int,
     *     legacy_queue_payloads: int
     * }
     */
    public function report(): array
    {
        $nonTerminalPayments = $this->nonTerminalPaymentCount();
        $dispatchableCancellationWork = $this->dispatchableCancellationWorkCount();
        $legacyQueuePayloads = $this->legacyQueuePayloadCount();
        $approval = $this->approval();
        $ownerApproved = $approval?->hasValidOwnerApproval() ?? false;
        $approvedBy = $ownerApproved ? (int) $approval->approved_by : null;
        $approvedAt = $ownerApproved ? $approval->approved_at?->toIso8601String() : null;
        $settlementReady = $nonTerminalPayments === 0 && $dispatchableCancellationWork === 0;
        $queueSafe = $legacyQueuePayloads === 0;
        $retirementReady = $settlementReady && $queueSafe && $ownerApproved;
        $ownerApprovalRequired = $settlementReady && $queueSafe && ! $ownerApproved;

        return [
            'provider' => PaymentProvider::PAKASIR,
            'gate' => $retirementReady
                ? 'ready_for_contract'
                : (! $settlementReady || ! $queueSafe ? 'blocked' : 'awaiting_owner_approval'),
            'settlement_ready' => $settlementReady,
            'queue_safe' => $queueSafe,
            'retirement_ready' => $retirementReady,
            'owner_approval_required' => $ownerApprovalRequired,
            'owner_approved' => $ownerApproved,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'non_terminal_payments' => $nonTerminalPayments,
            'dispatchable_cancellation_work' => $dispatchableCancellationWork,
            'legacy_queue_payloads' => $legacyQueuePayloads,
        ];
    }

    public function assertSatisfied(): void
    {
        $report = $this->report();
        if ($report['retirement_ready']) {
            return;
        }

        throw new RuntimeException(
            'Pakasir retirement contract is blocked: gate='.$report['gate']
            .', non_terminal_payments='.$report['non_terminal_payments']
            .', dispatchable_cancellation_work='.$report['dispatchable_cancellation_work']
            .', legacy_queue_payloads='.$report['legacy_queue_payloads']
            .', owner_approved='.($report['owner_approved'] ? 'true' : 'false').'.',
        );
    }

    public function approve(User $owner): PakasirRetirementApproval
    {
        if ($owner->role !== UserRole::Owner) {
            throw new InvalidArgumentException('Only an owner can approve Pakasir retirement.');
        }

        if (! Schema::hasTable('pakasir_retirement_approvals')) {
            throw new RuntimeException('Pakasir retirement approval storage is unavailable.');
        }

        return DB::transaction(function () use ($owner): PakasirRetirementApproval {
            $this->lockSettlementRows();
            $report = $this->report();
            if (! $report['settlement_ready'] || ! $report['queue_safe']) {
                throw new RuntimeException('Pakasir retirement cannot be approved before settlement and queue work are clear.');
            }

            $approval = PakasirRetirementApproval::query()
                ->where('singleton_key', PakasirRetirementApproval::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();
            if ($approval === null) {
                $approval = new PakasirRetirementApproval([
                    'singleton_key' => PakasirRetirementApproval::SINGLETON_KEY,
                ]);
            }

            $wasApproved = $approval->hasValidOwnerApproval();
            $oldValues = [
                'approved_by' => $approval->approved_by,
                'approved_at' => $approval->approved_at?->toIso8601String(),
            ];
            $approval->forceFill([
                'approved_by' => $owner->getKey(),
                'approved_at' => now(),
            ])->save();

            if (! $wasApproved || (int) $oldValues['approved_by'] !== (int) $owner->getKey()) {
                app(AuditLogger::class)->log(
                    'pakasir.retirement.approved',
                    $approval,
                    $oldValues,
                    [
                        'approved_by' => $owner->getKey(),
                        'approved_at' => $approval->approved_at?->toIso8601String(),
                    ],
                    $owner,
                );
            }

            return $approval->fresh();
        }, attempts: 5);
    }

    private function nonTerminalPaymentCount(): int
    {
        if (! Schema::hasTable('payments')) {
            return 1;
        }

        return (int) Payment::query()
            ->whereRaw('LOWER(provider) = ?', [PaymentProvider::PAKASIR])
            ->where(function ($query): void {
                $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', self::TERMINAL_PAYMENT_STATUSES);
            })
            ->count();
    }

    private function dispatchableCancellationWorkCount(): int
    {
        if (! Schema::hasTable('provider_cancellation_attempts')) {
            return 1;
        }

        return (int) ProviderCancellationAttempt::query()
            ->whereRaw('LOWER(provider) = ?', [PaymentProvider::PAKASIR])
            ->whereIn('state', ProviderCancellationAttempt::dispatchableStates())
            ->count();
    }

    private function legacyQueuePayloadCount(): int
    {
        $count = 0;
        foreach (['jobs', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                return 1;
            }

            $count += (int) DB::table($table)
                ->where(function ($query): void {
                    $query
                        ->where('payload', 'like', '%SendPakasirCheckoutInstructions%')
                        ->orWhere('payload', 'like', '%ProcessProviderCancellationAttempt%');
                })
                ->count();
        }

        return $count;
    }

    private function approval(): ?PakasirRetirementApproval
    {
        return Schema::hasTable('pakasir_retirement_approvals')
            ? PakasirRetirementApproval::current()
            : null;
    }

    private function lockSettlementRows(): void
    {
        if (Schema::hasTable('payments')) {
            Payment::query()
                ->whereRaw('LOWER(provider) = ?', [PaymentProvider::PAKASIR])
                ->lockForUpdate()
                ->get(['id']);
        }

        if (Schema::hasTable('provider_cancellation_attempts')) {
            ProviderCancellationAttempt::query()
                ->whereRaw('LOWER(provider) = ?', [PaymentProvider::PAKASIR])
                ->lockForUpdate()
                ->get(['id']);
        }
    }
}
