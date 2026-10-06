<?php

namespace App\Models;

use App\Enums\BalanceAlertSeverity;
use App\Enums\BalanceAlertState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Durable dedupe/state row for one operational alert key, for example
 * `reconciliation_drift` or `treasury_coverage`. Presence of an open episode
 * means the condition is currently true; delivery is owned by the Telegram
 * Outbound Effect ledger, and this row only remembers the episode and its
 * sanitized evidence so repeated evaluations do not send duplicate alerts.
 */
#[Fillable([
    'alert_key',
    'severity',
    'state',
    'summary',
    'fingerprint',
    'evidence',
    'occurrences',
    'open_count',
    'first_seen_at',
    'last_seen_at',
    'last_notified_at',
    'resolved_at',
])]
class BalanceOperationsAlertEpisode extends Model
{
    protected function casts(): array
    {
        return [
            'severity' => BalanceAlertSeverity::class,
            'state' => BalanceAlertState::class,
            'evidence' => 'array',
            'occurrences' => 'integer',
            'open_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'last_notified_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('state', BalanceAlertState::Open->value);
    }
}
