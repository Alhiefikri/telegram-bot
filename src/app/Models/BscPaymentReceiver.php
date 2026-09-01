<?php

namespace App\Models;

use App\Enums\BscReceiverVerificationStatus;
use App\Exceptions\BscReceiverDeletionBlocked;
use Database\Factories\BscPaymentReceiverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

#[Fillable([
    'label',
    'address',
    'address_normalized',
    'chain_id',
    'token_contract',
    'token_decimals',
    'is_active',
    'verification_status',
    'verification_method',
    'verified_at',
    'verified_by',
    'last_assigned_at',
])]
class BscPaymentReceiver extends Model
{
    /** @use HasFactory<BscPaymentReceiverFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'token_decimals' => 'integer',
            'is_active' => 'boolean',
            'verification_status' => BscReceiverVerificationStatus::class,
            'verified_at' => 'datetime',
            'last_assigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $receiver): void {
            $address = self::normalizeAddress((string) $receiver->address);
            $receiver->address = $address;
            $receiver->address_normalized = $address;

            $contract = strtolower(trim((string) $receiver->token_contract));
            if ($contract !== '' && preg_match('/^0x[a-f0-9]{40}$/', $contract) !== 1) {
                throw new InvalidArgumentException('BSC token contract must be a valid EVM address.');
            }
            $receiver->token_contract = $contract;
        });

        static::deleting(function (self $receiver): void {
            if (! Schema::hasTable('payment_bsc_details')) {
                return;
            }

            if (DB::table('payment_bsc_details')->where('receiver_id', $receiver->getKey())->exists()) {
                throw new BscReceiverDeletionBlocked(
                    'BSC Receiving Address yang sudah direferensikan Payment tidak boleh dihapus. Nonaktifkan address tersebut.',
                );
            }
        });
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function ownershipChallenges(): HasMany
    {
        return $this->hasMany(BscReceiverOwnershipChallenge::class, 'receiver_id');
    }

    public function paymentBscDetails(): HasMany
    {
        return $this->hasMany(PaymentBscDetail::class, 'receiver_id');
    }

    public function isCryptographicallyVerified(): bool
    {
        return $this->verification_status === BscReceiverVerificationStatus::Verified;
    }

    public function isVerified(): bool
    {
        return $this->verification_status?->isVerified() ?? false;
    }

    public function isReadyForAssignment(): bool
    {
        return $this->is_active && $this->isVerified();
    }

    public static function normalizeAddressInput(string $address): string
    {
        $trimmed = preg_replace('/\A[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+\z/u', '', $address);

        return strtolower($trimmed ?? trim($address));
    }

    public static function normalizeAddress(string $address): string
    {
        $normalized = self::normalizeAddressInput($address);
        if (preg_match('/\A0x[a-f0-9]{40}\z/D', $normalized) !== 1) {
            throw new InvalidArgumentException('BSC Receiving Address harus berupa address EVM 0x + 40 karakter hex.');
        }

        return $normalized;
    }

    public function maskedAddress(): string
    {
        $address = $this->address_normalized ?: $this->address;
        if (strlen((string) $address) < 12) {
            return '••••';
        }

        return substr((string) $address, 0, 6).'••••'.substr((string) $address, -4);
    }

    /** @return Builder<self> */
    public function scopeActiveVerified($query)
    {
        return $query
            ->where('is_active', true)
            ->whereIn('verification_status', [
                BscReceiverVerificationStatus::Verified->value,
                BscReceiverVerificationStatus::ManualVerified->value,
            ]);
    }
}
