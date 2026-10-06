<?php

namespace App\Models;

use App\Enums\BalanceRestoreGateState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton post-restore gate (#558). One row with id 1 records the operator
 * declared restore window, the completed external evidence replay and the
 * reconciliation evidence that allowed the gate to be cleared. The row is
 * operational state only: it never moves money.
 */
#[Fillable([
    'id',
    'state',
    'window_start',
    'window_end',
    'reason',
    'opened_by',
    'opened_at',
    'replay_completed_at',
    'replay_by',
    'replay_evidence',
    'cleared_at',
    'cleared_by',
    'cleared_evidence',
])]
class BalanceRestoreGate extends Model
{
    public const SINGLETON_ID = 1;

    protected function casts(): array
    {
        return [
            'state' => BalanceRestoreGateState::class,
            'window_start' => 'datetime',
            'window_end' => 'datetime',
            'opened_at' => 'datetime',
            'replay_completed_at' => 'datetime',
            'replay_evidence' => 'array',
            'cleared_at' => 'datetime',
            'cleared_evidence' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['state' => BalanceRestoreGateState::Cleared->value],
        );
    }

    public function isOpen(): bool
    {
        return $this->state === BalanceRestoreGateState::Open;
    }
}
