<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['payload', 'claim_token', 'external_event_id', 'resource_id'])]
final class SupplierWebhookEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'processed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
