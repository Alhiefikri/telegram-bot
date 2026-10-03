<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['idempotency_key', 'claim_token', 'payment_url', 'account_id_snapshot', 'credential_fingerprint'])]
final class SupplierDeposit extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'payment_url' => 'encrypted', 'expires_at' => 'immutable_datetime', 'paid_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime', 'dispatched_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }
}
