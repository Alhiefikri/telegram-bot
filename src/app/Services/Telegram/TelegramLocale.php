<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use Carbon\CarbonInterface;

class TelegramLocale
{
    public const DEFAULT_LOCALE = 'en';

    public const STORE_CHANNEL_LOCALE = 'en';

    public function __construct(private TelegramTranslationCatalog $catalog) {}

    public function code(?Customer $customer): string
    {
        return $this->normalize($customer?->locale);
    }

    public function translateLocale(?string $locale, string $key, array $replace = []): string
    {
        return $this->catalog->translate($key, $this->normalize($locale), $replace);
    }

    public function codeForLocale(?string $locale): string
    {
        return $this->normalize($locale);
    }

    private function normalize(?string $locale): string
    {
        $locale = (string) ($locale ?? '');

        return $this->isSupported($locale) ? $locale : self::DEFAULT_LOCALE;
    }

    public function localeFromCallbackData(string $data): ?string
    {
        if (preg_match('/^locale:(?<locale>[a-z]{2})$/', $data, $matches) !== 1) {
            return null;
        }

        $locale = $matches['locale'];

        return $this->isSupported($locale) ? $locale : null;
    }

    private function isSupported(string $locale): bool
    {
        return in_array($locale, TelegramTranslationCatalog::SUPPORTED_LOCALES, true);
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    public function translate(?Customer $customer, string $key, array $replace = []): string
    {
        return $this->catalog->translate($key, $this->code($customer), $replace);
    }

    public function checkoutMaintenanceNote(Customer $customer, ?string $publicNote): string
    {
        if ($publicNote === null) {
            return '';
        }

        return $this->translate($customer, 'maintenance.admin_note', ['note' => $publicNote]);
    }

    public function orderStatus(Customer $customer, OrderStatus $status): string
    {
        return $this->translate($customer, match ($status) {
            OrderStatus::Draft => 'status.order.draft',
            OrderStatus::PendingPayment => 'status.order.pending_payment',
            OrderStatus::Processing => 'status.order.processing',
            OrderStatus::Completed => 'status.order.completed',
            OrderStatus::Cancelled => 'status.order.cancelled',
            OrderStatus::Expired => 'status.order.expired',
        });
    }

    public function paymentStatus(Customer $customer, PaymentStatus $status): string
    {
        return $this->translate($customer, match ($status) {
            PaymentStatus::Pending => 'status.payment.order.pending',
            PaymentStatus::Paid => 'status.payment.order.paid',
            PaymentStatus::Failed => 'status.payment.order.failed',
            PaymentStatus::Expired => 'status.payment.order.expired',
            PaymentStatus::Refunded => 'status.payment.order.refunded',
        });
    }

    public function accountPaymentStatus(Customer $customer, PaymentStatus $status): string
    {
        return $this->translate($customer, match ($status) {
            PaymentStatus::Pending => 'status.payment.pending',
            PaymentStatus::Paid => 'status.payment.paid',
            PaymentStatus::Failed => 'status.payment.failed',
            PaymentStatus::Expired => 'status.payment.expired',
            PaymentStatus::Refunded => 'status.payment.refunded',
        });
    }

    public function paymentMethod(Customer $customer, Payment $payment): string
    {
        $label = trim((string) $payment->payment_method_label);

        return match (strtolower($label)) {
            'qris', 'hosted' => 'QRIS',
            'binance_pay_id', 'binance pay' => 'Binance Pay',
            'bank_transfer', 'bank transfer' => $this->translate($customer, 'payment.method.bank_transfer'),
            default => $label !== '' ? $label : $this->translate($customer, 'payment.method.unknown'),
        };
    }

    public function qrisCrossBorderSupport(Customer $customer): string
    {
        return $this->translate($customer, 'payment.qris_cross_border');
    }

    public function formatDate(Customer $customer, CarbonInterface $date): string
    {
        $timezone = (string) config('app.timezone');
        $localized = $date->copy()->timezone($timezone);

        return $this->dateParts($customer, $localized, true).' '.$this->timezoneLabel($timezone);
    }

    public function formatShortDate(Customer $customer, CarbonInterface $date): string
    {
        return $this->dateParts($customer, $date->copy()->timezone((string) config('app.timezone')), false);
    }

    public function idr(int $amount): string
    {
        return 'Rp '.number_format($amount, 0, ',', '.');
    }

    public function usdt(int $micro): string
    {
        return number_format($micro / 1_000_000, 6, '.', '');
    }

    public function languageKeyboard(): array
    {
        $buttons = [];
        foreach (TelegramTranslationCatalog::SUPPORTED_LOCALES as $locale) {
            $buttons[] = [
                'text' => $this->catalog->translate('language.option.'.$locale, $locale),
                'callback_data' => 'locale:'.$locale,
            ];
        }

        return ['inline_keyboard' => [$buttons]];
    }

    private function dateParts(Customer $customer, CarbonInterface $date, bool $withTime): string
    {
        $month = $this->translate($customer, 'date.month.'.(int) $date->format('n'));
        $formatted = $date->format('d').' '.$month.' '.$date->format('Y');

        return $withTime ? $formatted.' '.$date->format('H:i') : $formatted;
    }

    private function timezoneLabel(string $timezone): string
    {
        return match ($timezone) {
            'Asia/Jakarta' => 'WIB',
            'Asia/Makassar', 'Asia/Ujung_Pandang' => 'WITA',
            'Asia/Jayapura' => 'WIT',
            default => $timezone,
        };
    }
}
