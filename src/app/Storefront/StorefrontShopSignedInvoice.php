<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Throwable;

class StorefrontShopSignedInvoice
{
    public function __construct(
        private readonly TelegramQrCodeRenderer $qrCodes,
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirCallbackProcessor $processor,
        private readonly StorefrontInvoiceUrl $invoiceUrls,
        private readonly CredentialDeliveryFormatter $credentialFormatter,
    ) {}

    public function read(Order $order): StorefrontShopSignedInvoiceReadResult
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);

        $payment = $this->latestPayment($order);
        $state = $this->state($order, $payment);
        $redirectUrl = $this->redirectUrl($order);

        return new StorefrontShopSignedInvoiceReadResult(
            order: $order,
            payment: $payment,
            state: $state,
            statusUrl: $this->invoiceUrls->status($order),
            redirectUrl: $redirectUrl,
            payableAmount: $payment?->provider_total_payment ?? $payment?->amount,
            payableAmountFromProvider: $payment?->provider_total_payment !== null,
            qrisImageDataUri: $this->qrisImageDataUri($payment),
            hostedPaymentUrl: $this->hostedPaymentUrl($payment),
            accountGroups: $this->accountGroups($order, $state),
            statusMessage: $this->statusMessage($order, $payment),
        );
    }

    public function refreshStatus(Order $order): StorefrontShopSignedStatusRefreshResult
    {
        $payment = $this->latestPayment($order);

        if (! $this->isPakasirPayment($payment)) {
            return StorefrontShopSignedStatusRefreshResult::unsupportedPayment();
        }

        if ($this->shouldRefreshFromProvider($order, $payment)) {
            try {
                $this->processor->processReconciliationForPayment($payment, $this->pakasir->transactionDetail($payment));
            } catch (Throwable $exception) {
                report($exception);

                return StorefrontShopSignedStatusRefreshResult::retryableProviderFailure();
            }
        }

        $order->refresh();
        $payment->refresh();

        return StorefrontShopSignedStatusRefreshResult::refreshed(
            paymentStatus: $payment->status,
            orderStatus: $order->order_status,
            isPaid: $this->isPaid($order, $payment),
            isTerminal: $this->state($order, $payment) === StorefrontShopSignedInvoiceState::Terminal,
            redirectUrl: $this->redirectUrl($order),
            message: $this->statusMessage($order, $payment),
        );
    }

    private function latestPayment(Order $order): ?Payment
    {
        /** @var Payment|null */
        return $order->payments()
            ->latest('created_at')
            ->first();
    }

    private function state(Order $order, ?Payment $payment): StorefrontShopSignedInvoiceState
    {
        if ($this->isPaid($order, $payment)) {
            return StorefrontShopSignedInvoiceState::Paid;
        }

        if ($this->isPendingPayment($order, $payment)) {
            return StorefrontShopSignedInvoiceState::Pending;
        }

        return StorefrontShopSignedInvoiceState::Terminal;
    }

    private function isPaid(Order $order, ?Payment $payment): bool
    {
        return $payment?->status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
    }

    private function isPendingPayment(Order $order, ?Payment $payment): bool
    {
        return $payment?->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment;
    }

    private function shouldRefreshFromProvider(Order $order, Payment $payment): bool
    {
        return $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment;
    }

    private function isPakasirPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === 'pakasir';
    }

    private function qrisImageDataUri(?Payment $payment): ?string
    {
        if ($payment?->status !== PaymentStatus::Pending || ! filled($payment->provider_payment_number)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($this->qrCodes->png($payment->provider_payment_number));
    }

    private function hostedPaymentUrl(?Payment $payment): ?string
    {
        if ($payment?->status !== PaymentStatus::Pending || ! $this->isPakasirPayment($payment)) {
            return null;
        }

        try {
            return $this->pakasir->hostedPaymentUrl($payment);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function statusMessage(Order $order, ?Payment $payment): string
    {
        return match (true) {
            $this->isPaid($order, $payment) => 'Pembayaran terverifikasi. Invoice akan dibuka ulang untuk menampilkan data akun.',
            $payment?->status === PaymentStatus::Expired || $order->order_status === OrderStatus::Expired => 'Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.',
            $payment?->status === PaymentStatus::Failed => 'Pembayaran gagal. Jangan bayar QRIS ini; buat order baru atau hubungi admin.',
            $order->order_status === OrderStatus::Cancelled => 'Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.',
            default => 'Pembayaran belum masuk. Jika sudah bayar, coba cek lagi beberapa saat lagi.',
        };
    }

    /**
     * @return list<array{product_name: string, secrets: list<string>, delivery_note: ?string}>
     */
    private function accountGroups(Order $order, StorefrontShopSignedInvoiceState $state): array
    {
        if ($state !== StorefrontShopSignedInvoiceState::Paid) {
            return [];
        }

        return $order->orderItems
            ->map(fn (OrderItem $item): array => [
                'product_name' => (string) $item->product_name,
                'delivery_note' => $item->delivery_note_snapshotted ? $item->delivery_note : $item->product?->delivery_note,
                'secrets' => $item->stockItems
                    ->map(fn ($stockItem): string => $this->credentialFormatter->format((string) $stockItem->secret))
                    ->filter(fn (string $secret): bool => trim($secret) !== '')
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $group): bool => $group['secrets'] !== [])
            ->values()
            ->all();
    }

    private function redirectUrl(Order $order): string
    {
        return $this->invoiceUrls->show($order);
    }
}
