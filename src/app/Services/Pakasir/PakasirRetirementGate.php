<?php

namespace App\Services\Pakasir;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\ProviderCancellationAttempt;
use App\Support\PaymentProvider;

final class PakasirRetirementGate
{
    /** @return array{provider: string, gate: string, settlement_ready: bool, owner_approval_required: bool, non_terminal_payments: int, dispatchable_cancellation_work: int} */
    public function report(): array
    {
        $nonTerminalPayments = Payment::query()
            ->where('provider', PaymentProvider::PAKASIR)
            ->whereNotIn('status', [
                PaymentStatus::Paid->value,
                PaymentStatus::Failed->value,
                PaymentStatus::Expired->value,
                PaymentStatus::Refunded->value,
            ])
            ->count();

        $dispatchableCancellationWork = ProviderCancellationAttempt::query()
            ->where('provider', PaymentProvider::PAKASIR)
            ->whereIn('state', ProviderCancellationAttempt::dispatchableStates())
            ->count();

        $settlementReady = $nonTerminalPayments === 0 && $dispatchableCancellationWork === 0;

        return [
            'provider' => PaymentProvider::PAKASIR,
            'gate' => $settlementReady ? 'awaiting_owner_approval' : 'blocked',
            'settlement_ready' => $settlementReady,
            'owner_approval_required' => $settlementReady,
            'non_terminal_payments' => $nonTerminalPayments,
            'dispatchable_cancellation_work' => $dispatchableCancellationWork,
        ];
    }
}
