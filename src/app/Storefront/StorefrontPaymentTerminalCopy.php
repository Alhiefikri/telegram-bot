<?php

namespace App\Storefront;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationStatus;
use App\Services\Binance\BinanceVerificationSubmissionOutcome;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\Bsc\BscVerificationStatus;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
use App\Services\OrderLifecycleProjection;

final class StorefrontPaymentTerminalCopy
{
    public function locale(): string
    {
        $locale = strtolower(str_replace('_', '-', trim((string) config('storefront.locale', 'id'))));
        $locale = explode('-', $locale, 2)[0];

        return in_array($locale, ['id', 'en', 'ms'], true) ? $locale : 'id';
    }

    /** @param array<string, mixed> $replace */
    public function translate(string $key, array $replace = []): string
    {
        return (string) __($key, $replace, $this->locale());
    }

    public function message(OrderLifecycleProjection $projection, ?Payment $payment): string
    {
        if ($projection->isPaid) {
            return $this->translate('storefront.invoice.terminal.paid');
        }

        $isBinance = $payment?->method === 'BINANCE_PAY_ID';

        return match ($projection->terminalCause) {
            'expired' => $this->translate($isBinance
                ? 'storefront.invoice.terminal.expired.binance'
                : 'storefront.invoice.terminal.expired.qris'),
            'cancelled' => $this->translate($isBinance
                ? 'storefront.invoice.terminal.cancelled.binance'
                : 'storefront.invoice.terminal.cancelled.qris'),
            'provider_failed' => $this->translate($isBinance
                ? 'storefront.invoice.terminal.provider_failed.binance'
                : 'storefront.invoice.terminal.provider_failed.qris'),
            default => $this->translate('storefront.invoice.terminal.default'),
        };
    }

    public function binanceStatusMessage(OrderLifecycleProjection $projection, Payment $payment, BinanceVerificationStatus $verificationStatus): string
    {
        if ($projection->isTerminal || $projection->isPaid || ! $verificationStatus->automatic) {
            return $this->message($projection, $payment);
        }

        if ($verificationStatus->outcome === BinanceVerificationOutcome::NotConfigured) {
            return $this->translate('storefront.invoice.binance.status.not_configured');
        }

        if ($verificationStatus->mode === 'amount_unique') {
            return $this->translate($verificationStatus->outcome === BinanceVerificationOutcome::Mismatch
                ? 'storefront.invoice.binance.status.amount_unique.mismatch'
                : 'storefront.invoice.binance.status.amount_unique.pending');
        }

        return match (true) {
            $verificationStatus->outcome === BinanceVerificationOutcome::Mismatch => $this->translate('storefront.invoice.binance.status.order_id.mismatch'),
            $verificationStatus->outcome === BinanceVerificationOutcome::ProviderError
                && $verificationStatus->withinGrace => $this->translate('storefront.invoice.binance.status.order_id.provider_error_grace'),
            $verificationStatus->outcome === BinanceVerificationOutcome::IncompleteWindow
                && $verificationStatus->withinGrace => $this->translate('storefront.invoice.binance.status.order_id.checking'),
            $verificationStatus->outcome === BinanceVerificationOutcome::NotFound
                && $verificationStatus->withinGrace => $this->translate('storefront.invoice.binance.status.order_id.not_found_grace'),
            $verificationStatus->submitted
                && in_array($verificationStatus->outcome, [
                    BinanceVerificationOutcome::ProviderError,
                    BinanceVerificationOutcome::IncompleteWindow,
                    BinanceVerificationOutcome::NotFound,
                ], true) => $this->translate('storefront.invoice.binance.status.order_id.closed'),
            default => $this->translate('storefront.invoice.binance.status.order_id.submit'),
        };
    }

    public function bscInvoiceStatusMessage(BscVerificationStatus $status): string
    {
        return match ($status->outcome) {
            BscVerificationOutcome::Confirmed,
            BscVerificationOutcome::AlreadyPaid => $this->translate('storefront.invoice.bsc.status.confirmed'),
            BscVerificationOutcome::Confirming => $this->translate('storefront.invoice.bsc.status.confirming'),
            BscVerificationOutcome::ManualReview,
            BscVerificationOutcome::Mismatch,
            BscVerificationOutcome::TransactionAlreadyUsed => $this->translate('storefront.invoice.bsc.status.manual_review'),
            BscVerificationOutcome::ProviderError => $this->translate('storefront.invoice.bsc.status.provider_error'),
            default => $this->translate('storefront.invoice.bsc.status.pending'),
        };
    }

    public function bscRefreshStatusMessage(BscVerificationOutcome $outcome, ?int $confirmations, int $required): string
    {
        return match ($outcome) {
            BscVerificationOutcome::Confirmed,
            BscVerificationOutcome::AlreadyPaid => $this->translate('storefront.invoice.bsc.refresh.confirmed'),
            BscVerificationOutcome::Confirming => $this->translate('storefront.invoice.bsc.refresh.confirming', [
                'confirmations' => $confirmations ?? 0,
                'required' => $required,
            ]),
            BscVerificationOutcome::ProviderError => $this->translate('storefront.invoice.bsc.refresh.provider_error'),
            BscVerificationOutcome::ManualReview,
            BscVerificationOutcome::Mismatch,
            BscVerificationOutcome::TransactionAlreadyUsed => $this->translate('storefront.invoice.bsc.refresh.manual_review'),
            default => $this->translate('storefront.invoice.bsc.refresh.pending'),
        };
    }

    /** @return array{state: string, title: string, message: string, retry_label: string} */
    public function qrisReadiness(string $state): array
    {
        $state = in_array($state, [
            'terminal',
            'manual',
            'hosted',
            'ready',
            'creating',
            'recovery_pending',
            'provider_total_pending',
            'failed',
            'expired',
        ], true) ? $state : 'creating';

        return [
            'state' => $state,
            'title' => $this->translate('storefront.invoice.qris.readiness.'.$state.'.title'),
            'message' => $this->translate('storefront.invoice.qris.readiness.'.$state.'.message'),
            'retry_label' => $this->translate('storefront.invoice.qris.readiness.'.$state.'.retry_label'),
        ];
    }

    public function bscStatusLabel(string $status): string
    {
        $status = trim($status);
        $status = in_array($status, [
            'awaiting_transfer',
            'tx_submitted',
            'confirming',
            'confirmed',
            'not_found',
            'provider_error',
            'mismatch',
            'manual_review',
            'transaction_already_used',
            'expired',
        ], true) ? $status : 'pending';

        return $this->translate('storefront.invoice.bsc.status_label.'.$status);
    }

    public function orderStatusLabel(?OrderStatus $status): string
    {
        return $this->translate('storefront.invoice.status.order.'.($status?->value ?? 'unknown'));
    }

    public function paymentStatusLabel(?PaymentStatus $status): string
    {
        return $this->paymentStatusLabelValue($status?->value);
    }

    public function effectivePaymentStatus(Order $order, ?Payment $payment): string
    {
        $status = match ($order->order_status) {
            OrderStatus::Cancelled => 'cancelled',
            OrderStatus::Expired => 'expired',
            default => $payment?->status?->value ?? $order->payment_status?->value,
        };

        return $this->paymentStatusLabelValue($status);
    }

    public function binanceReceiverLabel(?string $receiverType, bool $hasPayId): string
    {
        $key = strtolower((string) $receiverType) === 'uid'
            ? 'uid'
            : (strtolower((string) $receiverType) === 'pay_id' || $hasPayId ? 'pay_id' : 'uid');

        return $this->translate('storefront.invoice.binance.receiver.'.$key);
    }

    /** @return array<string, string> */
    public function binanceOrderIdValidationMessages(): array
    {
        return [
            'binance_order_id.required' => $this->translate('storefront.invoice.binance.submission.required'),
            'binance_order_id.string' => $this->translate('storefront.invoice.binance.submission.string'),
            'binance_order_id.max' => $this->translate('storefront.invoice.binance.submission.max'),
            'binance_order_id.regex' => $this->binanceOrderIdValidationMessage(),
        ];
    }

    public function binanceOrderIdValidationMessage(): string
    {
        return $this->translate('storefront.invoice.binance.submission.validation');
    }

    public function binanceOrderIdSubmissionMessage(BinanceVerificationSubmissionOutcome $outcome, bool $dispatchFailed = false): string
    {
        return match ($outcome) {
            BinanceVerificationSubmissionOutcome::Accepted => $this->translate($dispatchFailed
                ? 'storefront.invoice.binance.submission.accepted_recovery'
                : 'storefront.invoice.binance.submission.accepted'),
            BinanceVerificationSubmissionOutcome::Limit => $this->translate('storefront.invoice.binance.submission.limit'),
            BinanceVerificationSubmissionOutcome::Expired => $this->translate('storefront.invoice.binance.submission.expired'),
            BinanceVerificationSubmissionOutcome::GraceExpired => $this->translate('storefront.invoice.binance.submission.grace_expired'),
            BinanceVerificationSubmissionOutcome::Manual => $this->translate('storefront.invoice.binance.submission.manual'),
            default => $this->translate('storefront.invoice.binance.submission.invalid'),
        };
    }

    /** @return array<string, string> */
    public function bscTxHashValidationMessages(): array
    {
        return [
            'bsc_tx_hash.required' => $this->translate('storefront.invoice.bsc.submission.required'),
            'bsc_tx_hash.string' => $this->translate('storefront.invoice.bsc.submission.string'),
            'bsc_tx_hash.max' => $this->translate('storefront.invoice.bsc.submission.max'),
            'bsc_tx_hash.regex' => $this->bscTxHashValidationMessage(),
        ];
    }

    public function bscTxHashValidationMessage(): string
    {
        return $this->translate('storefront.invoice.bsc.submission.validation');
    }

    public function bscTxHashSubmissionMessage(BscVerificationSubmissionOutcome $outcome, bool $dispatchFailed = false): string
    {
        return match ($outcome) {
            BscVerificationSubmissionOutcome::Accepted => $this->translate($dispatchFailed
                ? 'storefront.invoice.bsc.submission.accepted_recovery'
                : 'storefront.invoice.bsc.submission.accepted'),
            BscVerificationSubmissionOutcome::Limit => $this->translate('storefront.invoice.bsc.submission.limit'),
            BscVerificationSubmissionOutcome::Expired,
            BscVerificationSubmissionOutcome::GraceExpired => $this->translate('storefront.invoice.bsc.submission.expired'),
            BscVerificationSubmissionOutcome::AlreadyPaid => $this->translate('storefront.invoice.bsc.submission.already_paid'),
            BscVerificationSubmissionOutcome::TransactionAlreadyUsed => $this->translate('storefront.invoice.bsc.submission.transaction_already_used'),
            BscVerificationSubmissionOutcome::OrderNotPending,
            BscVerificationSubmissionOutcome::NotConfigured => $this->translate('storefront.invoice.bsc.submission.not_pending'),
            BscVerificationSubmissionOutcome::Invalid => $this->translate('storefront.invoice.bsc.submission.invalid'),
        };
    }

    /** @return array<string, string> */
    public function statusUiCopy(string $paymentKind): array
    {
        $kind = in_array($paymentKind, ['qris', 'binance', 'bsc'], true) ? $paymentKind : 'qris';
        $prefix = 'storefront.invoice.status_ui.'.$kind.'.';

        return [
            'loading_title' => $this->translate($prefix.'loading_title'),
            'loading_message' => $this->translate($prefix.'loading_message'),
            'network_title' => $this->translate('storefront.invoice.status_ui.common.network_title'),
            'network_message' => $this->translate('storefront.invoice.status_ui.common.network_message'),
            'pending_title' => $this->translate($prefix.'pending_title'),
            'pending_message' => $this->translate($prefix.'pending_message'),
            'paid_title' => $this->translate('storefront.invoice.status_ui.common.paid_title'),
            'paid_message' => $this->translate('storefront.invoice.status_ui.common.paid_message'),
            'qris_ready_title' => $this->translate('storefront.invoice.status_ui.common.qris_ready_title'),
            'qris_ready_message' => $this->translate('storefront.invoice.status_ui.common.qris_ready_message'),
            'terminal_title' => $this->translate('storefront.invoice.status_ui.common.terminal_title'),
            'terminal_message' => $this->translate('storefront.invoice.status_ui.common.terminal_message'),
            'retryable_title' => $this->translate('storefront.invoice.status_ui.common.retryable_title'),
            'retryable_message' => $this->translate('storefront.invoice.status_ui.common.retryable_message'),
            'rate_limited_title' => $this->translate('storefront.invoice.status_ui.common.rate_limited_title'),
            'rate_limited_message' => $this->translate('storefront.invoice.status_ui.common.rate_limited_message'),
            'retry_after' => $this->translate('storefront.invoice.status_ui.common.retry_after'),
            'unsupported_title' => $this->translate('storefront.invoice.status_ui.common.unsupported_title'),
            'unsupported_message' => $this->translate('storefront.invoice.status_ui.common.unsupported_message'),
            'manual_review_title' => $this->translate('storefront.invoice.status_ui.common.manual_review_title'),
            'manual_review_message' => $this->translate('storefront.invoice.status_ui.common.manual_review_message'),
            'bsc_confirming_title' => $this->translate('storefront.invoice.status_ui.bsc.confirming_title'),
            'bsc_confirming_message' => $this->translate('storefront.invoice.status_ui.bsc.confirming_message'),
        ];
    }

    private function paymentStatusLabelValue(?string $status): string
    {
        return $this->translate('storefront.invoice.status.payment.'.($status ?: 'unknown'));
    }
}
