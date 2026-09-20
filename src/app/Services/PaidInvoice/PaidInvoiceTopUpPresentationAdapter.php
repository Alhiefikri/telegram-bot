<?php

namespace App\Services\PaidInvoice;

use App\Models\Customer;
use App\Models\StoreBalanceTopUp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Adapts a settled Store Balance top-up into the public-safe invoice seam.
 *
 * Only immutable top-up payment fields and the masked Customer label are read,
 * so a public Store Channel proof never publishes raw identifiers.
 */
final class PaidInvoiceTopUpPresentationAdapter
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        private readonly PaidInvoiceBuyerNameMasker $buyerNameMasker,
    ) {}

    public function present(StoreBalanceTopUp $topUp): PaidInvoicePresentation
    {
        $topUp->loadMissing('customer');

        $amount = max(0, (int) $topUp->amount);
        $currency = strtoupper(self::safeText((string) ($topUp->currency ?: 'IDR'), 12) ?: 'IDR');

        return new PaidInvoicePresentation(
            invoiceId: $this->invoiceIdentifier($topUp),
            buyerIdentifier: $this->maskBuyerTelegramId($topUp->customer),
            buyerName: $this->buyerNameMasker->mask($topUp->customer),
            paidAt: $this->paidAt($topUp),
            paymentMethod: $this->paymentMethod($topUp),
            currency: $currency,
            itemCurrency: $currency,
            items: [[
                'product_name' => 'Store Balance Top-Up',
                'quantity' => 1,
                'quantity_price_tier_minimum' => null,
                'unit_price' => $amount,
                'subtotal' => $amount,
            ]],
            providerFee: max(0, (int) ($topUp->provider_fee ?? 0)),
            totalPaid: $topUp->provider_total_payment !== null
                ? max(0, (int) $topUp->provider_total_payment)
                : $amount,
        );
    }

    private function invoiceIdentifier(StoreBalanceTopUp $topUp): string
    {
        $reference = self::safeText(trim((string) $topUp->merchant_ref), 96);
        if ($reference !== '') {
            return $reference;
        }

        $key = self::safeText(trim((string) $topUp->getKey()), 96);

        return $key !== '' ? 'TOPUP-'.$key : 'TOPUP';
    }

    private function maskBuyerTelegramId(?Customer $customer): string
    {
        $raw = $customer?->getRawOriginal('telegram_id');
        $identifier = is_scalar($raw)
            ? trim((string) $raw)
            : trim((string) ($customer?->telegram_id ?? ''));

        if (! preg_match('/^\d{6,}$/', $identifier)) {
            return 'Buyer ID unavailable';
        }

        return substr($identifier, 0, 3).'*****'.substr($identifier, -2);
    }

    private function paidAt(StoreBalanceTopUp $topUp): CarbonImmutable
    {
        $value = $topUp->paid_at ?? $topUp->settled_at;
        if ($value === null) {
            throw new InvalidArgumentException('Store Balance top-up invoice timestamp is missing.');
        }

        try {
            return ($value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value)
            )->setTimezone(self::TIMEZONE);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Store Balance top-up invoice timestamp is invalid.', previous: $exception);
        }
    }

    private function paymentMethod(StoreBalanceTopUp $topUp): string
    {
        $method = strtolower(trim((string) $topUp->provider_payment_method));
        $label = match ($method) {
            'qris' => 'QRIS',
            'qr_manual' => 'QR Manual',
            default => strtoupper($method),
        };

        return self::safeText($label !== '' ? $label : 'Unknown', 48) ?: 'Unknown';
    }

    private static function safeText(string $value, int $limit): string
    {
        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);
        $clean = trim(is_string($clean) ? $clean : $value);

        return mb_strlen($clean, 'UTF-8') > $limit
            ? rtrim(mb_substr($clean, 0, max(1, $limit - 1), 'UTF-8')).'…'
            : $clean;
    }
}
