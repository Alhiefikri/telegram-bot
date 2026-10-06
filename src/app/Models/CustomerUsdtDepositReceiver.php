<?php

namespace App\Models;

use App\Enums\BscReceiverVerificationStatus;
use App\Enums\UsdtDepositReceiverSource;
use App\Exceptions\UsdtReceiverBindingImmutable;
use Database\Factories\CustomerUsdtDepositReceiverFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * One public BSC address bound permanently and exclusively to a single
 * Customer for USDT top-up funding. The binding, chain, token metadata and
 * custody namespace are immutable; retirement stops new invoices while the
 * row stays monitored for late money, and the address is never reassigned.
 *
 * The application stores public addresses and verification evidence only:
 * private keys, seeds and signing capability stay outside this application.
 */
#[Fillable([
    'customer_id',
    'address_claim_id',
    'chain_id',
    'address',
    'address_normalized',
    'token_contract',
    'token_decimals',
    'custody_namespace',
    'source',
    'verification_status',
    'verification_method',
    'verified_at',
    'verified_by',
    'registered_by',
])]
class CustomerUsdtDepositReceiver extends Model
{
    /** @use HasFactory<CustomerUsdtDepositReceiverFactory> */
    use HasFactory;

    /** Attributes that define the permanent Customer binding identity. */
    private const IMMUTABLE_IDENTITY = [
        'customer_id',
        'address_claim_id',
        'chain_id',
        'address',
        'address_normalized',
        'token_contract',
        'token_decimals',
        'custody_namespace',
        'source',
    ];

    /** Attributes that become final once a verification decision is recorded. */
    private const FINAL_AFTER_VERIFICATION = [
        'verification_status',
        'verification_method',
        'verified_at',
        'verified_by',
    ];

    protected function casts(): array
    {
        return [
            'chain_id' => 'integer',
            'token_decimals' => 'integer',
            'source' => UsdtDepositReceiverSource::class,
            'verification_status' => BscReceiverVerificationStatus::class,
            'verified_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $receiver): void {
            $address = self::normalizeAddress((string) $receiver->address);
            $receiver->address = $address;
            $receiver->address_normalized = $address;

            $contract = strtolower(trim((string) $receiver->token_contract));
            if (preg_match('/\A0x[a-f0-9]{40}\z/D', $contract) !== 1) {
                throw new InvalidArgumentException('USDT deposit receiver token contract must be a valid EVM address.');
            }
            $receiver->token_contract = $contract;

            if (! $receiver->exists) {
                return;
            }

            foreach (self::IMMUTABLE_IDENTITY as $attribute) {
                if ($receiver->isDirty($attribute)) {
                    throw new UsdtReceiverBindingImmutable(
                        "USDT deposit receiver {$attribute} is immutable. Retire the receiver and provision a new address.",
                    );
                }
            }

            $originalStatus = $receiver->getOriginal('verification_status');
            if ($originalStatus instanceof BscReceiverVerificationStatus
                && $originalStatus !== BscReceiverVerificationStatus::PendingVerification) {
                foreach (self::FINAL_AFTER_VERIFICATION as $attribute) {
                    if ($receiver->isDirty($attribute)) {
                        throw new UsdtReceiverBindingImmutable(
                            'A decided USDT deposit receiver verification is final.',
                        );
                    }
                }
            }

            $originalRetiredAt = $receiver->getOriginal('retired_at');
            if ($originalRetiredAt !== null && $receiver->isDirty('retired_at')) {
                throw new UsdtReceiverBindingImmutable('A retired USDT deposit receiver cannot be un-retired or re-dated.');
            }
        });

        static::deleting(function (self $receiver): void {
            if ($receiver->verification_status !== BscReceiverVerificationStatus::PendingVerification
                || $receiver->retired_at !== null) {
                throw new UsdtReceiverBindingImmutable(
                    'Bound USDT deposit receivers are immutable Customer history. Retire the receiver instead of deleting it.',
                );
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function addressClaim(): BelongsTo
    {
        return $this->belongsTo(UsdtReceiverAddressClaim::class, 'address_claim_id');
    }

    public function ownershipChallenges(): HasMany
    {
        return $this->hasMany(UsdtDepositReceiverOwnershipChallenge::class, 'receiver_id');
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    public function isRetired(): bool
    {
        return $this->retired_at !== null;
    }

    public function isVerified(): bool
    {
        return $this->verification_status?->isVerified() ?? false;
    }

    public function isReadyForAssignment(): bool
    {
        return ! $this->isRetired() && $this->isVerified();
    }

    public function maskedAddress(): string
    {
        $address = (string) ($this->address_normalized ?: $this->address);
        if (strlen($address) < 12) {
            return '••••';
        }

        return substr($address, 0, 6).'••••'.substr($address, -4);
    }

    /**
     * Uses the same EVM normalization as the checkout receiver registry so the
     * permanent address claim compares identical values across both channels.
     */
    public static function normalizeAddress(string $address): string
    {
        return BscPaymentReceiver::normalizeAddress($address);
    }

    /** @return Builder<self> */
    public function scopeActive($query)
    {
        return $query->whereNull('retired_at');
    }

    /** @return Builder<self> */
    public function scopeForCustomer($query, int|string $customerId)
    {
        return $query->where('customer_id', $customerId);
    }
}
