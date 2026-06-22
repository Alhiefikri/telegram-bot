<?php

namespace App\Models;

use App\Enums\PaymentEventType;
use Database\Factories\PaymentEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['payment_id', 'provider', 'event_type', 'merchant_ref', 'provider_reference', 'provider_status', 'raw_payload', 'signature', 'received_at', 'processed_at'])]
class PaymentEvent extends Model
{
    /** @use HasFactory<PaymentEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['event_type' => PaymentEventType::class, 'raw_payload' => 'array', 'received_at' => 'datetime', 'processed_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
