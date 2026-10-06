<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use LogicException;

#[Hidden(['session_binding', 'credential_binding'])]
final class BalanceAdjustmentRequest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'version' => 'integer', 'expires_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function ledgerEntry()
    {
        return $this->belongsTo(StoreBalanceEntry::class, 'ledger_entry_id');
    }

    protected static function booted(): void
    {
        self::updating(function (self $request): void {
            if (! in_array($request->getRawOriginal('state'), ['pending', 'approved'], true)
                || array_diff(array_keys($request->getDirty()), ['state', 'ledger_entry_id', 'resolved_at', 'updated_at']) !== []) {
                throw new LogicException('Adjustment preview is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Adjustment history is immutable.'));
    }
}
