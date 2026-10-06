<?php

namespace App\Services\Operations;

use App\Models\BalanceRestoreGate;

/**
 * Read-only post-restore gate check (#558).
 *
 * The funding/spending gate consults this on every availability check, so it
 * must stay a single indexed primary-key read and must never write. A missing
 * row (for example on a database restored before the gate existed, or a
 * migration-order artifact) is treated as closed: the gate only opens when an
 * operator explicitly records a restore window.
 */
final class BalanceRestoreGateReader
{
    public function isOpen(): bool
    {
        $gate = BalanceRestoreGate::query()
            ->whereKey(BalanceRestoreGate::SINGLETON_ID)
            ->first(['id', 'state']);

        return $gate !== null && $gate->isOpen();
    }

    public function current(): ?BalanceRestoreGate
    {
        return BalanceRestoreGate::query()->whereKey(BalanceRestoreGate::SINGLETON_ID)->first();
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $gate = $this->current();
        if ($gate === null) {
            return [
                'configured' => false,
                'state' => 'cleared',
                'open' => false,
                'window_start' => null,
                'window_end' => null,
                'reason' => null,
                'opened_by' => null,
                'opened_at' => null,
                'replay_completed_at' => null,
                'replay_by' => null,
                'replay_evidence' => null,
                'cleared_at' => null,
                'cleared_by' => null,
                'cleared_evidence' => null,
            ];
        }

        return [
            'configured' => true,
            'state' => $gate->state->value,
            'open' => $gate->isOpen(),
            'window_start' => $gate->window_start?->toIso8601String(),
            'window_end' => $gate->window_end?->toIso8601String(),
            'reason' => $gate->reason,
            'opened_by' => $gate->opened_by,
            'opened_at' => $gate->opened_at?->toIso8601String(),
            'replay_completed_at' => $gate->replay_completed_at?->toIso8601String(),
            'replay_by' => $gate->replay_by,
            'replay_evidence' => $gate->replay_evidence,
            'cleared_at' => $gate->cleared_at?->toIso8601String(),
            'cleared_by' => $gate->cleared_by,
            'cleared_evidence' => $gate->cleared_evidence,
        ];
    }
}
