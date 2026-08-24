<?php

namespace App\Services\PaidInvoice;

use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\PaymentReceiptReport;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Throwable;

/**
 * Adapts a paid persisted Order into the public-safe invoice seam.
 *
 * Only immutable OrderItem snapshots and the deterministic canonical paid
 * Payment are read. Mutable Product and credential relations are never used.
 */
final class PaidInvoiceOrderPresentationAdapter
{
    private const TIMEZONE = 'Asia/Jakarta';

    public function __construct(
        private readonly PaymentReceiptReport $receiptReport,
        private readonly PaidInvoiceBuyerNameMasker $buyerNameMasker,
    ) {}

    public function present(Order $order): PaidInvoicePresentation
    {
        $order->loadMissing(['customer', 'orderItems', 'payments']);

        $payment = $this->canonicalPaidPayment($order);

        return new PaidInvoicePresentation(
            invoiceId: $this->invoiceIdentifier($order),
            buyerIdentifier: $this->maskBuyerTelegramId($order->customer),
            buyerName: $this->buyerNameMasker->mask($order->customer),
            paidAt: $this->paidAt($payment),
            paymentMethod: $this->paymentMethod($payment),
            currency: $this->currency($payment, $order),
            items: $this->snapshotItems($order),
            providerFee: max(0, (int) ($payment->provider_fee ?? 0)),
            totalPaid: $payment->provider_total_payment !== null
                ? max(0, (int) $payment->provider_total_payment)
                : max(0, (int) $order->total_amount),
        );
    }

    private function canonicalPaidPayment(Order $order): Payment
    {
        $payments = $order->payments;
        if (! $payments instanceof Collection) {
            throw new InvalidArgumentException('Paid invoice Order payments are not available.');
        }

        $paidPayments = $payments
            ->filter(fn ($payment): bool => $payment instanceof Payment
                && (string) $payment->order_id === (string) $order->getKey()
                && $payment->status === PaymentStatus::Paid
                && $payment->paid_at !== null);

        $canonical = $this->receiptReport->canonicalFromPayments($paidPayments)->first();
        if (! $canonical instanceof Payment) {
            throw new InvalidArgumentException('Paid invoice requires a canonical paid Payment.');
        }

        return $canonical;
    }

    /**
     * @return list<array{product_name: string, quantity: int, unit_price: int, subtotal: int}>
     */
    private function snapshotItems(Order $order): array
    {
        $items = $order->orderItems;
        if (! $items instanceof Collection) {
            throw new InvalidArgumentException('Paid invoice Order Items are not available.');
        }

        return $items
            ->filter(static fn ($item): bool => $item instanceof OrderItem)
            ->values()
            ->map(static fn (OrderItem $item): array => [
                'product_name' => (string) $item->product_name,
                'quantity' => max(0, (int) $item->quantity),
                'unit_price' => max(0, (int) $item->unit_price),
                'subtotal' => max(0, (int) $item->subtotal),
            ])
            ->all();
    }

    private function invoiceIdentifier(Order $order): string
    {
        $invoice = self::safeText(trim((string) $order->invoice_number), 96);
        if ($invoice !== '') {
            return $invoice;
        }

        $key = self::safeText(trim((string) $order->getKey()), 96);

        return $key !== '' ? $key : 'ORDER';
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

    private function currency(Payment $payment, Order $order): string
    {
        $currency = trim((string) ($payment->currency ?: $order->currency ?: 'IDR'));

        return strtoupper(self::safeText($currency, 12) ?: 'IDR');
    }

    private function paidAt(Payment $payment): CarbonImmutable
    {
        $value = $payment->paid_at;
        if ($value === null) {
            throw new InvalidArgumentException('Paid invoice Payment timestamp is missing.');
        }

        try {
            return ($value instanceof CarbonInterface
                ? CarbonImmutable::instance($value)
                : CarbonImmutable::parse($value)
            )->setTimezone(self::TIMEZONE);
        } catch (Throwable $exception) {
            throw new InvalidArgumentException('Paid invoice Payment timestamp is invalid.', previous: $exception);
        }
    }

    private function paymentMethod(Payment $payment): string
    {
        $method = strtolower(trim((string) $payment->method));
        $label = trim((string) $payment->payment_method_label);

        $label = match ($method) {
            'api_qris', 'qris' => 'QRIS',
            'binance_pay_id' => 'Binance Pay',
            'hosted' => 'Pakasir',
            'qr_manual' => 'QR Manual',
            default => $label !== '' ? $label : $method,
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
