<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'singleton_key',
    'status',
    'fingerprint_key_digest',
    'scan_upper_bound_id',
    'last_processed_id',
    'scanned_count',
    'registered_count',
    'already_registered_count',
    'skipped_unrecognized_count',
    'conflict_count',
    'unresolved_count',
    'started_at',
    'completed_at',
    'ready_at',
])]
final class CredentialIdentityBackfillReadiness extends Model
{
    public const SINGLETON_KEY = 'credential_identity_registry';

    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $table = 'credential_identity_backfill_readiness';

    protected function casts(): array
    {
        return [
            'scan_upper_bound_id' => 'integer',
            'last_processed_id' => 'integer',
            'scanned_count' => 'integer',
            'registered_count' => 'integer',
            'already_registered_count' => 'integer',
            'skipped_unrecognized_count' => 'integer',
            'conflict_count' => 'integer',
            'unresolved_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'ready_at' => 'datetime',
        ];
    }
}
