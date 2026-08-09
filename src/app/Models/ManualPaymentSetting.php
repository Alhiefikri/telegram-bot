<?php

namespace App\Models;

use App\Support\PakasirCheckoutFlow;
use App\Support\PaymentProvider;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['payment_mode', 'pakasir_checkout_flow', 'admin_contact_url', 'admin_username', 'admin_telegram_chat_id', 'whatsapp_support_url', 'whatsapp_support_number', 'instructions', 'is_active', 'binance_pay_enabled', 'qris_payment_ttl_minutes', 'binance_payment_ttl_minutes', 'binance_verification_mode', 'binance_order_id_compatibility_ready_at'])]
class ManualPaymentSetting extends Model
{
    public const DEFAULT_QRIS_PAYMENT_TTL_MINUTES = 10;

    public const DEFAULT_BINANCE_PAYMENT_TTL_MINUTES = 20;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'binance_pay_enabled' => 'boolean',
            'binance_order_id_compatibility_ready_at' => 'datetime',
        ];
    }

    public function paymentProvider(): string
    {
        return PaymentProvider::normalize($this->payment_mode);
    }

    public function pakasirCheckoutFlow(): string
    {
        return PakasirCheckoutFlow::isAllowed($this->pakasir_checkout_flow)
            ? $this->pakasir_checkout_flow
            : PakasirCheckoutFlow::HOSTED;
    }

    public function qrisPaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->qris_payment_ttl_minutes ?: self::DEFAULT_QRIS_PAYMENT_TTL_MINUTES));
    }

    public function binancePaymentTtlMinutes(): int
    {
        return max(1, (int) ($this->binance_payment_ttl_minutes ?: self::DEFAULT_BINANCE_PAYMENT_TTL_MINUTES));
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
