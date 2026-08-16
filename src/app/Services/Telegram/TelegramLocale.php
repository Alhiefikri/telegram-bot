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

    /** @var array<string, string> */
    private const LANGUAGE_OPTIONS = [
        'id' => '🇮🇩 Bahasa Indonesia',
        'en' => '🇬🇧 English',
        'ms' => '🇲🇾 Bahasa Melayu',
    ];

    public function __construct(private TelegramTranslationCatalog $catalog) {}

    public function code(?Customer $customer): string
    {
        $locale = (string) ($customer?->locale ?? '');

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
        return array_key_exists($locale, self::LANGUAGE_OPTIONS);
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    public function translate(?Customer $customer, string $key, array $replace = []): string
    {
        return $this->catalog->translate($key, $this->code($customer), $replace);
    }

    /**
     * Legacy pair helper for surfaces that are migrated by later tickets.
     */
    public function text(?Customer $customer, string $id, string $en): string
    {
        return in_array($customer?->locale, [null, 'id'], true) ? $id : $en;
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
        return match ($status) {
            OrderStatus::Draft => $this->text($customer, 'Draft', 'Draft'),
            OrderStatus::PendingPayment => $this->text($customer, 'Menunggu pembayaran', 'Awaiting payment'),
            OrderStatus::Processing => $this->text($customer, 'Diproses', 'Processing'),
            OrderStatus::Completed => $this->text($customer, 'Selesai', 'Completed'),
            OrderStatus::Cancelled => $this->text($customer, 'Dibatalkan', 'Cancelled'),
            OrderStatus::Expired => $this->text($customer, 'Kedaluwarsa', 'Expired'),
        };
    }

    public function paymentStatus(Customer $customer, PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => $this->text($customer, 'Menunggu pembayaran', 'Awaiting payment'),
            PaymentStatus::Paid => $this->text($customer, 'Dibayar', 'Paid'),
            PaymentStatus::Failed => $this->text($customer, 'Gagal', 'Failed'),
            PaymentStatus::Expired => $this->text($customer, 'Kedaluwarsa', 'Expired'),
            PaymentStatus::Refunded => $this->text($customer, 'Dikembalikan', 'Refunded'),
        };
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
        foreach (self::LANGUAGE_OPTIONS as $locale => $label) {
            $buttons[] = ['text' => $label, 'callback_data' => 'locale:'.$locale];
        }

        return ['inline_keyboard' => [$buttons]];
    }

    private function dateParts(Customer $customer, CarbonInterface $date, bool $withTime): string
    {
        $months = match ($this->code($customer)) {
            'en' => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            'ms' => [1 => 'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun', 'Jul', 'Ogo', 'Sep', 'Okt', 'Nov', 'Dis'],
            default => [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'],
        };
        $formatted = $date->format('d').' '.$months[(int) $date->format('n')].' '.$date->format('Y');

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
