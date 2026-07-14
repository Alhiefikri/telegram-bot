<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Payment;
use Carbon\CarbonInterface;

class TelegramLocale
{
    public function code(?Customer $customer): string
    {
        return $customer?->locale === 'en' ? 'en' : 'id';
    }

    public function text(?Customer $customer, string $id, string $en): string
    {
        return $this->code($customer) === 'en' ? $en : $id;
    }

    public function orderStatus(Customer $customer, OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Draft => 'Draft',
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

    public function paymentMethod(Customer $customer, Payment $payment): string
    {
        $label = trim((string) $payment->payment_method_label);

        return match (strtolower($label)) {
            'qris', 'hosted' => 'QRIS',
            'binance_pay_id', 'binance pay' => 'Binance Pay',
            'bank_transfer', 'bank transfer' => $this->text($customer, 'Transfer bank', 'Bank transfer'),
            default => $label !== '' ? $label : $this->text($customer, 'Tidak diketahui', 'Unknown'),
        };
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
        return ['inline_keyboard' => [[['text' => '🇮🇩 Bahasa Indonesia', 'callback_data' => 'locale:id'], ['text' => '🇬🇧 English', 'callback_data' => 'locale:en']]]];
    }

    private function dateParts(Customer $customer, CarbonInterface $date, bool $withTime): string
    {
        $months = $this->code($customer) === 'en'
            ? [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
            : [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
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
