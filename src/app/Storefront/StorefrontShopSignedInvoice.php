<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Services\CredentialDeliveryFormatter;
use App\Services\OrderLifecycle;
use App\Services\OrderLifecycleProjection;
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
        private readonly StorefrontFunnelEvents $events,
        private readonly OrderLifecycle $orders,
    ) {}

    public function read(Order $order): StorefrontShopSignedInvoiceReadResult
    {
        $order->loadMissing(['customer', 'orderItems.stockItems', 'orderItems.product']);

        $payment = $this->latestPayment($order);
        $projection = $this->orders->project($order, $payment);
        $state = $this->state($projection);
        $redirectUrl = $this->redirectUrl($order);
        $qrisReadiness = $this->qrisReadiness($projection, $payment);
        $accountGroups = $this->accountGroups($order, $state);

        return new StorefrontShopSignedInvoiceReadResult(
            order: $order,
            payment: $payment,
            state: $state,
            statusUrl: $this->invoiceUrls->status($order),
            redirectUrl: $redirectUrl,
            cancelUrl: $this->invoiceUrls->cancel($order),
            canCancel: $this->canCancel($order, $projection),
            payableAmount: $qrisReadiness['is_payable'] ? $payment?->provider_total_payment : null,
            payableAmountFromProvider: $qrisReadiness['is_payable'],
            isQrisPayable: $qrisReadiness['is_payable'],
            qrisReadinessState: $qrisReadiness['state'],
            qrisReadinessTitle: $qrisReadiness['title'],
            qrisReadinessMessage: $qrisReadiness['message'],
            qrisRetryLabel: $qrisReadiness['retry_label'],
            qrisImageDataUri: $this->qrisImageDataUri($payment),
            hostedPaymentUrl: $this->hostedPaymentUrl($payment),
            accountGroups: $accountGroups,
            accountCopyText: $this->accountCopyText($accountGroups),
            paidDeliveryType: $this->paidDeliveryType($order, $state, $accountGroups),
            manualFulfillmentStatus: $this->manualFulfillmentStatus($order, $payment),
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
        $qrisRecoveredDuringRetry = false;

        if ($this->shouldRecoverQris($order, $payment)) {
            try {
                $this->nativeQris->ensureCreated($payment);
            } catch (Throwable $exception) {
                report($exception);
                $qrisRecoveryFailed = true;
            }

            $payment->refresh();
            if (! $wasQrisReady && $this->hasPayableNativeQris($payment)) {
                $qrisRecoveredDuringRetry = true;
                $this->events->qrisCreated($order, $payment, [
                    'source' => 'status_retry',
                    'idempotent_reuse' => false,
                ]);
            }
        }

        if ($qrisRecoveryFailed && ! $this->hasPayableNativeQris($payment)) {
            $readiness = $this->qrisReadiness($this->orders->project($order, $payment), $payment);
            $this->events->checkoutSubmitted(null, $order, [
                'status_endpoint_result' => 'qris_not_ready',
                'qris_readiness_state' => $readiness['state'],
                'failure_kind' => 'qris_recovery_failed',
            ]);

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
                $this->events->checkoutSubmitted(null, $order, [
                    'status_endpoint_result' => 'provider_failure',
                    'failure_kind' => $exception::class,
                ]);

                return StorefrontShopSignedStatusRefreshResult::retryableProviderFailure();
            }
        }

        $order->refresh();
        $payment->refresh();
        $projection = $this->orders->project($order, $payment);
        $qrisReady = $this->hasPayableNativeQris($payment);
        if (! $wasQrisReady && $qrisReady && ! $qrisRecoveredDuringRetry) {
            $this->events->qrisCreated($order, $payment, [
                'source' => 'status_refresh',
                'idempotent_reuse' => false,
            ]);
        }
        if ($projection->isPaid) {
            $this->events->paymentVerified($order, $payment, [
                'source' => 'status_refresh',
                'status_endpoint_result' => 'paid',
            ]);
        }
        if ($projection->isTerminal) {
            $this->events->checkoutSubmitted(null, $order, [
                'status_endpoint_result' => 'terminal',
                'recovery_cause' => $projection->terminalCause,
            ]);
        }
        $shouldReload = ! $projection->isPaid
            && ! $projection->isTerminal
            && ! $wasQrisReady
            && $qrisReady;

        return StorefrontShopSignedStatusRefreshResult::refreshed(
            paymentStatus: $payment->status,
            orderStatus: $order->order_status,
            isPaid: $projection->isPaid,
            isTerminal: $this->state($projection) === StorefrontShopSignedInvoiceState::Terminal,
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

    private function state(OrderLifecycleProjection $projection): StorefrontShopSignedInvoiceState
    {
        if ($projection->isPaid) {
            return StorefrontShopSignedInvoiceState::Paid;
        }

        if ($projection->isPendingPayment) {
            return StorefrontShopSignedInvoiceState::Pending;
        }

        return StorefrontShopSignedInvoiceState::Terminal;
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

    private function canCancel(Order $order, OrderLifecycleProjection $projection): bool
    {
        return $projection->isPendingPayment
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
        $projection = $this->orders->project($order, $payment);

        return match (true) {
            $projection->isPaid => 'Pembayaran terverifikasi. Invoice akan dibuka ulang untuk menampilkan data akun.',
            $projection->terminalCause === 'expired' => 'Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.',
            $projection->terminalCause === 'cancelled' => 'Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.',
            $projection->terminalCause === 'provider_failed' => 'Pembayaran gagal. Jangan bayar QRIS ini; buat order baru atau hubungi admin.',
            default => 'Pembayaran belum masuk. Jika sudah bayar, coba cek lagi beberapa saat lagi.',
        };
    }

    /**
     * @return array{is_payable: bool, state: string, title: string, message: string, retry_label: string}
     */
    private function qrisReadiness(OrderLifecycleProjection $projection, ?Payment $payment): array
    {
        if (! $projection->isPendingPayment) {
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

    /**
     * @return list<array{product_name: string, secrets: list<array{label: string, text: string, parts: ?array{email: string, password: string, mfa: string}}>, copy_text: string, delivery_note: ?string}>
     */
    private function accountGroups(Order $order, StorefrontShopSignedInvoiceState $state): array
    {
        if ($state !== StorefrontShopSignedInvoiceState::Paid) {
            return [];
        }

        return $order->orderItems
            ->map(function (OrderItem $item): array {
                $secrets = $item->stockItems
                    ->map(function ($stockItem): array {
                        $rawSecret = (string) $stockItem->secret;

                        return [
                            'text' => $this->credentialFormatter->format($rawSecret),
                            'parts' => $this->credentialFormatter->parts($rawSecret),
                        ];
                    })
                    ->filter(fn (array $secret): bool => trim($secret['text']) !== '')
                    ->values()
                    ->map(fn (array $secret, int $index): array => array_merge($secret, [
                        'label' => 'Data akun '.($index + 1),
                    ]))
                    ->all();

                return [
                    'product_name' => (string) $item->product_name,
                    'delivery_note' => $item->delivery_note_snapshotted ? $item->delivery_note : $item->product?->delivery_note,
                    'secrets' => $secrets,
                    'copy_text' => collect($secrets)
                        ->map(fn (array $secret, int $index): string => ($index + 1).".\n".$secret['text'])
                        ->implode("\n\n"),
                ];
            })
            ->filter(fn (array $group): bool => $group['secrets'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{product_name: string, secrets: list<array{label: string, text: string, parts: ?array{email: string, password: string, mfa: string}}>, copy_text: string, delivery_note: ?string}>  $accountGroups
     */
    private function accountCopyText(array $accountGroups): string
    {
        return collect($accountGroups)
            ->map(fn (array $group): string => $group['product_name']."\n".$group['copy_text'])
            ->implode("\n\n");
    }

    /**
     * @param  list<array{product_name: string, secrets: list<array{label: string, text: string, parts: ?array{email: string, password: string, mfa: string}}>, copy_text: string, delivery_note: ?string}>  $accountGroups
     */
    private function paidDeliveryType(Order $order, StorefrontShopSignedInvoiceState $state, array $accountGroups): string
    {
        if ($state !== StorefrontShopSignedInvoiceState::Paid) {
            return 'none';
        }

        if ($accountGroups !== []) {
            return 'automatic_available';
        }

        return $this->expectsAutomaticCredentials($order) ? 'credentials_unavailable' : 'manual';
    }

    private function manualFulfillmentStatus(Order $order, ?Payment $payment): string
    {
        if ($order->order_status === OrderStatus::Completed && strtolower((string) $payment?->provider) !== 'pakasir') {
            return 'completed';
        }

        return 'processing';
    }

    private function expectsAutomaticCredentials(Order $order): bool
    {
        return $order->orderItems->contains(fn (OrderItem $item): bool => $item->product?->isCredentialManaged() === true);
    }

    private function redirectUrl(Order $order): string
    {
        return $this->invoiceUrls->show($order);
    }
}
