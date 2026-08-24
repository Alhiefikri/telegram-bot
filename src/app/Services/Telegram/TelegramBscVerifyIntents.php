<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\ManualPaymentSetting;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscTransactionHash;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\Bsc\BscVerificationQueue;
use App\Services\Bsc\BscVerificationSubmissionOutcome;
use Throwable;

final class TelegramBscVerifyIntents
{
    public function __construct(
        private readonly TelegramBotClient $bot,
        private readonly TelegramLocale $locale,
        private readonly TelegramBscVerifySession $session,
        private readonly BscPaymentVerification $verification,
        private readonly BscVerificationQueue $queue,
    ) {}

    public function handleSubmitTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [$order, $payment] = $this->findPayment($customer, $orderId);
        if ($order === null || $payment === null) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->translate($customer, 'payment.bsc.not_pending', ['invoice' => $order?->invoice_number ?? $orderId]));

            return;
        }

        if ($message = $this->guardMessage($customer, $order, $payment)) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $message);

            return;
        }

        $this->session->promptOrder($customer, $order->id);
        $this->bot->answerCallbackQuery($callbackQueryId);
        $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.hash_prompt'));
    }

    public function handleCheckTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [$order, $payment] = $this->findPayment($customer, $orderId);
        if ($order === null || $payment === null) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->translate($customer, 'payment.error.order_not_found'));

            return;
        }

        if ($message = $this->guardMessage($customer, $order, $payment)) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $message);

            return;
        }

        try {
            $this->queue->dispatchForScan($payment);
        } catch (Throwable $exception) {
            report($exception);
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.provider_error'));

            return;
        }

        $this->bot->answerCallbackQuery($callbackQueryId, $this->translate($customer, 'payment.bsc.check_requested'));
        $this->bot->sendMessage($chatId, $this->statusMessage($customer, $order, $payment));
    }

    public function handleHashInput(Customer $customer, string $chatId, string $text): bool
    {
        $orderId = $this->session->promptedOrderId($customer);
        if ($orderId === null) {
            return false;
        }

        [$order, $payment] = $this->findPayment($customer, $orderId);
        if ($order === null || $payment === null) {
            $this->session->forget($customer);

            return false;
        }

        if ($message = $this->guardMessage($customer, $order, $payment)) {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $message);

            return true;
        }

        $candidate = BscTransactionHash::normalize($text);
        if ($candidate === null) {
            // Invalid format is deliberately not passed to the domain service,
            // so it consumes neither a valid attempt nor an RPC lookup.
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.hash_invalid'));

            return true;
        }

        $result = $this->verification->submitTransactionHash($order->id, $candidate);
        switch ($result->outcome) {
            case BscVerificationSubmissionOutcome::Accepted:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $result->dispatchFailed
                    ? $this->translate($customer, 'payment.bsc.provider_error')
                    : $this->translate($customer, 'payment.bsc.hash_received'));

                return true;
            case BscVerificationSubmissionOutcome::Limit:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.hash_limit', ['invoice' => $order->invoice_number]));

                return true;
            case BscVerificationSubmissionOutcome::Expired:
            case BscVerificationSubmissionOutcome::GraceExpired:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.expired', ['invoice' => $order->invoice_number]));

                return true;
            case BscVerificationSubmissionOutcome::AlreadyPaid:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.already_paid', ['invoice' => $order->invoice_number]));

                return true;
            case BscVerificationSubmissionOutcome::TransactionAlreadyUsed:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.transaction_used'));

                return true;
            case BscVerificationSubmissionOutcome::OrderNotPending:
            case BscVerificationSubmissionOutcome::NotConfigured:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.not_pending', ['invoice' => $order->invoice_number]));

                return true;
            case BscVerificationSubmissionOutcome::Invalid:
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.bsc.hash_invalid'));

                return true;
        }

        return true;
    }

    public function cancelPrompt(Customer $customer): void
    {
        $this->session->forget($customer);
    }

    /** @return array{0: ?Order, 1: ?Payment} */
    private function findPayment(Customer $customer, string $orderId): array
    {
        $order = $customer->orders()->with('payments')->whereKey($orderId)->first();
        $payment = $order?->payments->first(fn (Payment $payment): bool => $payment->provider === 'manual'
            && $payment->method === 'USDT_BEP20');

        return [$order, $payment];
    }

    private function guardMessage(Customer $customer, Order $order, Payment $payment): ?string
    {
        if ($payment->status === PaymentStatus::Paid) {
            return $this->translate($customer, 'payment.bsc.already_paid', ['invoice' => $order->invoice_number]);
        }
        if ($order->order_status !== OrderStatus::PendingPayment || $payment->status !== PaymentStatus::Pending) {
            return $this->translate($customer, 'payment.bsc.not_pending', ['invoice' => $order->invoice_number]);
        }

        return null;
    }

    private function statusMessage(Customer $customer, Order $order, Payment $payment): string
    {
        $status = $this->verification->status($payment);

        return match ($status->outcome) {
            BscVerificationOutcome::Confirmed,
            BscVerificationOutcome::AlreadyPaid => $this->translate($customer, 'payment.bsc.confirmed', ['invoice' => $order->invoice_number]),
            BscVerificationOutcome::Confirming => $this->translate($customer, 'payment.bsc.confirming', [
                'confirmations' => $status->confirmations ?? 0,
                'required' => ManualPaymentSetting::current()?->bscMinConfirmations() ?? 3,
            ]),
            BscVerificationOutcome::Mismatch,
            BscVerificationOutcome::TransactionAlreadyUsed,
            BscVerificationOutcome::ManualReview => $this->translate($customer, 'payment.bsc.manual_review', ['invoice' => $order->invoice_number]),
            BscVerificationOutcome::ProviderError => $this->translate($customer, 'payment.bsc.provider_error'),
            default => $this->translate($customer, 'payment.bsc.not_found', ['invoice' => $order->invoice_number]),
        };
    }

    /** @param array<string, scalar|null> $replace */
    private function translate(Customer $customer, string $key, array $replace = []): string
    {
        return $this->locale->translate($customer, $key, $replace);
    }
}
