<?php

namespace App\Models;

use App\Exceptions\UsdtReceiverBindingImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * One Operator-controlled shared BSC address for the bsc_shared top-up rail.
 * The address, chain and token identity are immutable. Rotation deactivates the
 * current row and registers a new claimed address; history is never deleted.
 */
#[Fillable([
    'address_claim_id',
    'label',
    'chain_id',
    'address',
    'address_normalized',
    'token_contract',
    'token_decimals',
    'is_active',
    'created_by',
])]
class UsdtSharedTopUpReceiver extends Model
{
    private const IMMUTABLE_IDENTITY = [
        'address_claim_id',
        'chain_id',
        'address',
        'address_normalized',
        'token_contract',
        'token_decimals',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'token_decimals' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $receiver): void {
            $address = self::normalizeAddress((string) $receiver->address);
            $receiver->address = $address;
            $receiver->address_normalized = $address;
            $receiver->token_contract = strtolower(trim((string) $receiver->token_contract));

            if ($receiver->exists) {
                foreach (self::IMMUTABLE_IDENTITY as $attribute) {
                    if ($receiver->isDirty($attribute)) {
                        throw new UsdtReceiverBindingImmutable(
                            "Shared top-up receiver {$attribute} is immutable. Rotate the receiver instead.",
                        );
                    }
                }
            }
        });

        static::deleting(function (): void {
            throw new UsdtReceiverBindingImmutable('Shared top-up receiver history cannot be deleted. Deactivate it instead.');
        });
    }

    public function addressClaim(): BelongsTo
    {
        return $this->belongsTo(UsdtReceiverAddressClaim::class, 'address_claim_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return Builder<self> */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public static function normalizeAddress(string $address): string
    {
        $normalized = strtolower(trim($address));
        if (preg_match('/\A0x[a-f0-9]{40}\z/D', $normalized) !== 1) {
            throw new InvalidArgumentException('Shared top-up address must be a 0x-prefixed EVM address.');
        }

        return $normalized;
    }

    public function maskedAddress(): string
    {
        $address = (string) ($this->address_normalized ?: $this->address);
        if (strlen($address) < 12) {
            return '••••';
        }

        return substr($address, 0, 6).'••••'.substr($address, -4);
    }
}
