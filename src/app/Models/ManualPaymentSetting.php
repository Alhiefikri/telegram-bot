<?php

namespace App\Models;

use App\Support\PaymentProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'payment_mode',
    'payment_provider_version',
    'admin_contact_url',
    'admin_username',
    'admin_telegram_chat_id',
    'whatsapp_support_url',
    'whatsapp_support_number',
    'instructions',
    'is_active',
    'binance_pay_enabled',
    'qris_payment_ttl_minutes',
    'binance_payment_ttl_minutes',
    'binance_verification_mode',
    'binance_order_id_compatibility_ready_at',
    'bsc_payment_enabled',
    'bsc_payment_ttl_minutes',
    'bsc_min_confirmations',
    'bsc_require_finalized_block',
    'bsc_confirmation_grace_minutes',
    'bsc_unique_amount_increment_micro',
    'bsc_hash_attempt_limit',
])]
class ManualPaymentSetting extends Model
{
    public const DEFAULT_QRIS_PAYMENT_TTL_MINUTES = 10;

    public const DEFAULT_BINANCE_PAYMENT_TTL_MINUTES = 20;

    public const DEFAULT_BSC_PAYMENT_TTL_MINUTES = 30;

    public const DEFAULT_BSC_MIN_CONFIRMATIONS = 3;

    public const DEFAULT_BSC_CONFIRMATION_GRACE_MINUTES = 3;

    public const DEFAULT_BSC_UNIQUE_AMOUNT_INCREMENT_MICRO = 100;

    public const DEFAULT_BSC_HASH_ATTEMPT_LIMIT = 5;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_provider_version' => 'integer',
            'binance_pay_enabled' => 'boolean',
            'binance_order_id_compatibility_ready_at' => 'datetime',
            'bsc_payment_enabled' => 'boolean',
            'bsc_require_finalized_block' => 'boolean',
            'bsc_payment_ttl_minutes' => 'integer',
            'bsc_min_confirmations' => 'integer',
            'bsc_confirmation_grace_minutes' => 'integer',
            'bsc_unique_amount_increment_micro' => 'integer',
            'bsc_hash_attempt_limit' => 'integer',
        ];
    }

    public function paymentProvider(): string
    {
        return PaymentProvider::normalize($this->payment_mode);
    }

    public function qrisPaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->qris_payment_ttl_minutes ?: self::DEFAULT_QRIS_PAYMENT_TTL_MINUTES));
    }

    public function binancePaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->binance_payment_ttl_minutes ?: self::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES));
    }

    public function bscPaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->bsc_payment_ttl_minutes ?: self::DEFAULT_BSC_PAYMENT_TTL_MINUTES));
    }

    public function bscMinConfirmations(): int
    {
        return max(self::DEFAULT_BSC_MIN_CONFIRMATIONS, (int) ($this->bsc_min_confirmations ?: self::DEFAULT_BSC_MIN_CONFIRMATIONS));
    }

    public function bscConfirmationGraceMinutes(): int
    {
        return max(0, (int) ($this->bsc_confirmation_grace_minutes ?? self::DEFAULT_BSC_CONFIRMATION_GRACE_MINUTES));
    }

    public function bscUniqueAmountIncrementMicro(): int
    {
        return max(1, (int) ($this->bsc_unique_amount_increment_micro ?: self::DEFAULT_BSC_UNIQUE_AMOUNT_INCREMENT_MICRO));
    }

    public function bscHashAttemptLimit(): int
    {
        return max(1, (int) ($this->bsc_hash_attempt_limit ?: self::DEFAULT_BSC_HASH_ATTEMPT_LIMIT));
    }

    public function whatsappSupportNumber(): ?string
    {
        $number = self::normalizeWhatsappNumber((string) $this->whatsapp_support_number);

        return $number !== '' ? $number : null;
    }

    public function whatsappSupportUrl(): ?string
    {
        $url = trim((string) $this->whatsapp_support_url);
        if ($url !== '') {
            return $url;
        }

        $number = $this->whatsappSupportNumber();

        return $number ? 'https://wa.me/'.$number : null;
    }

    public static function normalizeWhatsappNumber(string $value): string
    {
        $number = preg_replace('/\D+/', '', $value) ?: '';

        if (str_starts_with($number, '0')) {
            return '62'.substr($number, 1);
        }

        return $number;
    }

    public static function current(): ?self
    {
        return self::query()->where('is_active', true)->oldest('id')->first();
    }
}
