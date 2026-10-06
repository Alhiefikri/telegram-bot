<?php

namespace App\Models;

use App\Enums\UsdtReceiverChannel;
use App\Exceptions\UsdtReceiverBindingImmutable;
use Database\Factories\UsdtReceiverAddressClaimFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The permanent, channel-exclusive ownership claim of one normalized
 * (chain_id, address_normalized) receiver address. It is the single
 * cross-table uniqueness authority that keeps the checkout receiver pool and
 * Customer-exclusive USDT deposit receivers from ever sharing an address.
 */
#[Fillable(['chain_id', 'address_normalized', 'channel', 'claimed_at'])]
class UsdtReceiverAddressClaim extends Model
{
    /** @use HasFactory<UsdtReceiverAddressClaimFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'channel' => UsdtReceiverChannel::class,
            'claimed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $claim): void {
            if ($claim->exists) {
                throw new UsdtReceiverBindingImmutable('USDT receiver address claims are permanent and channel-exclusive.');
            }
        });

        static::deleting(function (self $claim): void {
            throw new UsdtReceiverBindingImmutable('USDT receiver address claims are permanent and channel-exclusive.');
        });
    }

    public function checkoutReceiver(): HasOne
    {
        return $this->hasOne(BscPaymentReceiver::class, 'address_claim_id');
    }

    public function depositReceiver(): HasOne
    {
        return $this->hasOne(CustomerUsdtDepositReceiver::class, 'address_claim_id');
    }

    /** @return Builder<self> */
    public function scopeForAddress($query, int $chainId, string $addressNormalized)
    {
        return $query
            ->where('chain_id', $chainId)
            ->where('address_normalized', $addressNormalized);
    }
}
