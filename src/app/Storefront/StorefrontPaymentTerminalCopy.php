<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\OrderLifecycleProjection;

final class StorefrontPaymentTerminalCopy
{
    public function message(OrderLifecycleProjection $projection, ?Payment $payment): string
    {
        if ($projection->isPaid) {
            return 'Pembayaran terverifikasi. Invoice akan dibuka ulang untuk menampilkan data akun.';
        }

        $isBinance = $payment?->method === 'BINANCE_PAY_ID';

        return match ($projection->terminalCause) {
            'expired' => $isBinance
                ? 'Invoice Binance sudah kedaluwarsa. Jangan kirim USDT untuk invoice ini; buat checkout Binance baru dari katalog.'
                : 'Invoice QRIS sudah kedaluwarsa. Jangan bayar QRIS ini; buat order baru dari katalog.',
            'cancelled' => $isBinance
                ? 'Order dibatalkan. Jangan kirim USDT untuk invoice ini; buat checkout Binance baru jika masih ingin membeli.'
                : 'Order dibatalkan. Jangan bayar QRIS ini; buat order baru jika masih ingin membeli.',
            'provider_failed' => $isBinance
                ? 'Verifikasi Binance gagal. Jangan kirim USDT untuk invoice ini; buat checkout baru atau hubungi admin.'
                : 'Pembayaran gagal. Jangan bayar QRIS ini; buat order baru atau hubungi admin.',
            default => 'Pembayaran belum masuk. Jika sudah bayar, coba cek lagi beberapa saat lagi.',
        };
    }

    public function binanceStatusMessage(OrderLifecycleProjection $projection, Payment $payment, BinanceVerificationStatus $verificationStatus): string
    {
        if ($projection->isTerminal || $projection->isPaid || ! $verificationStatus->automatic) {
            return $this->message($projection, $payment);
        }

        if ($verificationStatus->outcome === BinanceVerificationOutcome::NotConfigured) {
            return 'Automatic Binance verification is unavailable. Please contact support with your payment proof.';
        }

        if ($verificationStatus->mode === 'amount_unique') {
            return match (true) {
                $verificationStatus->outcome === BinanceVerificationOutcome::Mismatch => 'This Binance payment needs a manual review. Please contact support with your invoice number.',
                default => 'We are checking Binance history for the exact amount and receiver. Your product will be delivered automatically when the transfer matches.',
            };
        }

        return match (true) {
            $verificationStatus->outcome === BinanceVerificationOutcome::Mismatch => 'This Binance payment needs a manual review. Please contact support with your invoice number.',
            $verificationStatus->outcome === BinanceVerificationOutcome::ProviderError
                && $verificationStatus->withinGrace => 'Your Order ID was received. Verification is temporarily delayed; we will keep checking securely.',
            $verificationStatus->outcome === BinanceVerificationOutcome::IncompleteWindow
                && $verificationStatus->withinGrace => 'Your Binance Pay Order ID is being checked securely.',
            $verificationStatus->outcome === BinanceVerificationOutcome::NotFound
                && $verificationStatus->withinGrace => 'The payment is not visible yet. We will keep checking while the verification window is open.',
            $verificationStatus->submitted
                && in_array($verificationStatus->outcome, [
                    BinanceVerificationOutcome::ProviderError,
                    BinanceVerificationOutcome::IncompleteWindow,
                    BinanceVerificationOutcome::NotFound,
                ], true) => 'Automatic verification has closed. Please contact support with your payment proof.',
            default => 'Submit your Binance Pay Order ID after completing the payment.',
        };
    }

    public function effectivePaymentStatus(Order $order, ?Payment $payment): string
    {
        return match ($order->order_status) {
            OrderStatus::Cancelled => 'cancelled',
            OrderStatus::Expired => 'expired',
            default => $payment?->status?->value ?? $order->payment_status?->value ?? '-',
        };
    }
}
