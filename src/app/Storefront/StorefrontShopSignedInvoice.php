<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirNativeQrisService;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Telegram\TelegramQrCodeRenderer;
use Throwable;

class StorefrontShopSignedInvoice
{
    public function __construct(
        private readonly TelegramQrCodeRenderer $qrCodes,
        private readonly PakasirProviderAdapter $pakasir,
        private readonly PakasirNativeQrisService $nativeQris,
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
        $qrisReadiness = $this->qrisReadiness($order, $payment);

        return new StorefrontShopSignedInvoiceReadResult(
            order: $order,
            payment: $payment,
            state: $state,
            statusUrl: $this->invoiceUrls->status($order),
            redirectUrl: $redirectUrl,
            cancelUrl: $this->invoiceUrls->cancel($order),
            canCancel: $this->canCancel($order, $payment),
            payableAmount: $qrisReadiness['is_payable'] ? $payment?->provider_total_payment : null,
            payableAmountFromProvider: $qrisReadiness['is_payable'],
            isQrisPayable: $qrisReadiness['is_payable'],
            qrisReadinessState: $qrisReadiness['state'],
            qrisReadinessTitle: $qrisReadiness['title'],
            qrisReadinessMessage: $qrisReadiness['message'],
            qrisRetryLabel: $qrisReadiness['retry_label'],
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

        $wasQrisReady = $this->hasPayableNativeQris($payment);
        $qrisRecoveryFailed = false;

        if ($this->shouldRecoverQris($order, $payment)) {
            try {
                $this->nativeQris->ensureCreated($payment);
            } catch (Throwable $exception) {
                report($exception);
                $qrisRecoveryFailed = true;
            }

            $payment->refresh();
        }

        if ($qrisRecoveryFailed && ! $this->hasPayableNativeQris($payment)) {
            $readiness = $this->qrisReadiness($order, $payment);

            return StorefrontShopSignedStatusRefreshResult::qrisNotReady(
                title: $readiness['title'],
                message: $readiness['message'],
            );
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
        $qrisReady = $this->hasPayableNativeQris($payment);
        $shouldReload = ! $this->isPaid($order, $payment)
            && ! $this->isTerminalStatus($order, $payment)
            && ! $wasQrisReady
            && $qrisReady;

        return StorefrontShopSignedStatusRefreshResult::refreshed(
            paymentStatus: $payment->status,
            orderStatus: $order->order_status,
            isPaid: $this->isPaid($order, $payment),
            isTerminal: $this->state($order, $payment) === StorefrontShopSignedInvoiceState::Terminal,
            qrisReady: $qrisReady,
            shouldReload: $shouldReload,
            redirectUrl: $this->redirectUrl($order),
            message: $shouldReload ? 'QRIS dan total bayar final sudah siap. Invoice akan dimuat ulang.' : $this->statusMessage($order, $payment),
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
        return $this->hasPayableNativeQris($payment)
            && $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment;
    }

    private function shouldRecoverQris(Order $order, Payment $payment): bool
    {
        return $this->isPakasirPayment($payment)
            && $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && ! $this->hasPayableNativeQris($payment);
    }

    private function canCancel(Order $order, ?Payment $payment): bool
    {
        return $this->isPendingPayment($order, $payment)
            && ! $order->payments()->whereNotNull('payment_proof_submitted_at')->exists();
    }

    private function isPakasirPayment(?Payment $payment): bool
    {
        return strtolower((string) $payment?->provider) === 'pakasir';
    }

    private function qrisImageDataUri(?Payment $payment): ?string
    {
        if ($payment?->status !== PaymentStatus::Pending || ! $this->hasPayableNativeQris($payment)) {
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
            $order->order_status === OrderStatus::Cancelled => 'Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.',
            $payment?->status === PaymentStatus::Failed => 'Pembayaran gagal. Jangan bayar QRIS ini; buat order baru atau hubungi admin.',
            default => 'Pembayaran belum masuk. Jika sudah bayar, coba cek lagi beberapa saat lagi.',
        };
    }

    /**
     * @return array{is_payable: bool, state: string, title: string, message: string, retry_label: string}
     */
    private function qrisReadiness(Order $order, ?Payment $payment): array
    {
        if (! $this->isPendingPayment($order, $payment)) {
            return [
                'is_payable' => false,
                'state' => 'terminal',
                'title' => 'QRIS tidak aktif',
                'message' => 'Invoice ini tidak sedang menunggu pembayaran QRIS.',
                'retry_label' => 'Cek status pembayaran',
            ];
        }

        if ($this->hasPayableNativeQris($payment)) {
            return [
                'is_payable' => true,
                'state' => 'ready',
                'title' => 'QRIS siap dibayar',
                'message' => 'Bayar hanya memakai QRIS dan total bayar final di invoice ini.',
                'retry_label' => 'Cek status pembayaran',
            ];
        }

        if (filled($payment?->provider_payment_number) && $payment?->provider_total_payment === null) {
            return [
                'is_payable' => false,
                'state' => 'provider_total_pending',
                'title' => 'Provider belum memberi nominal',
                'message' => 'QRIS sudah dibuat, tetapi total bayar final dari provider belum tersedia. Jangan bayar total produk.',
                'retry_label' => 'Coba ambil total QRIS',
            ];
        }

        if (in_array($payment?->provider_create_state, ['outcome_unknown', 'provider_total_pending'], true)) {
            return [
                'is_payable' => false,
                'state' => 'failed',
                'title' => 'Gagal membuat QRIS',
                'message' => 'QRIS belum siap dipakai. Coba buat ulang dari invoice ini atau hubungi bantuan jika tetap gagal.',
                'retry_label' => 'Coba buat QRIS lagi',
            ];
        }

        return [
            'is_payable' => false,
            'state' => 'creating',
            'title' => 'Sedang membuat QRIS',
            'message' => 'Provider sedang menyiapkan QRIS dan nominal final. Jangan bayar sebelum QRIS tampil.',
            'retry_label' => 'Coba cek QRIS',
        ];
    }

    private function hasPayableNativeQris(?Payment $payment): bool
    {
        return $payment?->isPayableNativePakasirQris() === true;
    }

    private function isTerminalStatus(Order $order, ?Payment $payment): bool
    {
        return $payment?->status === PaymentStatus::Expired
            || $payment?->status === PaymentStatus::Failed
            || in_array($order->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);
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
