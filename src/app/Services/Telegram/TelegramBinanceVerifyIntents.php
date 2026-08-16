<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Binance\BinanceOrderId;
use App\Services\Binance\BinancePaymentVerification;
use App\Services\Binance\BinanceVerificationOutcome;
use App\Services\Binance\BinanceVerificationQueue;
use App\Services\Binance\BinanceVerificationResult;
use App\Services\Binance\BinanceVerificationSubmissionOutcome;
use Throwable;

class TelegramBinanceVerifyIntents
{
    private const MAX_ORDER_ID_ATTEMPTS = 3;

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramBinanceVerifySession $session,
        private BinancePaymentVerification $verification,
        private BinanceVerificationQueue $queue,
        private TelegramBinanceVerificationEscalator $escalator,
    ) {}

    public function handleVerifyTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if ($payment !== null && $this->verification->status($payment)->mode === 'amount_unique') {
            $this->handleAmountUniqueTap($customer, $chatId, $callbackQueryId, $orderId);

            return;
        }

        $this->handleOrderIdPromptTap($customer, $chatId, $callbackQueryId, $orderId);
    }

    public function handleAmountUniqueTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [$order, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if (! $order || ! $payment) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->translate($customer, 'payment.error.order_not_found'));

            return;
        }

        if ($guardMessage = $this->amountUniqueGuardMessage($customer, $order, $payment)) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $guardMessage);

            return;
        }

        try {
            $this->queue->dispatchAmountUnique($payment);
        } catch (Throwable $exception) {
            report($exception);
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->respondToResult(
                $customer,
                $chatId,
                $order,
                $payment->fresh(),
                BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: false),
                allowRetryButtons: false,
            );

            return;
        }

        $this->bot->answerCallbackQuery($callbackQueryId, $this->translate($customer, 'payment.binance.check_requested'));
        $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.checking', [
            'invoice' => $order->invoice_number,
        ]));
    }

    public function verifyAmountUnique(string $paymentId): void
    {
        $payment = Payment::query()->with('order.customer')->find($paymentId);
        $order = $payment?->order;
        $customer = $order?->customer;

        if (! $payment || ! $order || ! $customer
            || $payment->status !== PaymentStatus::Pending
            || $order->order_status !== OrderStatus::PendingPayment
            || $this->verification->status($payment)->mode !== 'amount_unique') {
            return;
        }

        $result = $this->verification->verify($payment);
        $this->verification->recordOutcome($payment, $result);
        $this->respondToResult(
            $customer,
            (string) $customer->telegram_id,
            $order,
            $payment,
            $result,
            allowRetryButtons: true,
        );
    }

    public function handleOrderIdPromptTap(Customer $customer, string $chatId, string $callbackQueryId, string $orderId): void
    {
        [$order, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if (! $order || ! $payment) {
            $this->bot->answerCallbackQuery($callbackQueryId, $this->translate($customer, 'payment.error.order_not_found'));

            return;
        }

        if ($guardMessage = $this->guardMessage($customer, $order, $payment)) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $guardMessage);

            return;
        }

        if ((int) $payment->binance_order_id_attempts >= self::MAX_ORDER_ID_ATTEMPTS) {
            $this->bot->answerCallbackQuery($callbackQueryId);
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.max_order_ids', [
                'invoice' => $order->invoice_number,
            ]));

            return;
        }

        $this->session->promptOrder($customer, $order->id);
        $this->bot->answerCallbackQuery($callbackQueryId);
        $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.order_id_prompt'));
    }

    /**
     * Consume free-text input while the customer is being prompted for a
     * Binance order id. Returns true when the message was handled here.
     */
    public function handleOrderIdInput(Customer $customer, string $chatId, string $text): bool
    {
        $orderId = $this->session->promptedOrderId($customer);
        if ($orderId === null) {
            return false;
        }

        [$order, $payment] = $this->findVerifiableOrder($customer, $orderId);

        if (! $order || ! $payment) {
            $this->session->forget($customer);

            return false;
        }

        if ($guardMessage = $this->guardMessage($customer, $order, $payment)) {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $guardMessage);

            return true;
        }

        $candidate = BinanceOrderId::normalize($text);
        if ($candidate === null) {
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.order_id_invalid'));

            return true;
        }
        if (! $this->session->withinAttemptLimit($order->id)) {
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.too_many_attempts'));

            return true;
        }

        $submission = $this->verification->submitOrderId($order->id, $candidate);
        if ($submission->outcome === BinanceVerificationSubmissionOutcome::Limit) {
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.max_order_ids', [
                'invoice' => $order->invoice_number,
            ]));

            return true;
        }

        if ($submission->outcome === BinanceVerificationSubmissionOutcome::Expired) {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.deadline_late', [
                'invoice' => $order->invoice_number,
            ]));

            return true;
        }

        if ($submission->outcome === BinanceVerificationSubmissionOutcome::GraceExpired) {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.grace_expired', [
                'invoice' => $order->invoice_number,
            ]));

            return true;
        }

        if ($submission->outcome === BinanceVerificationSubmissionOutcome::Manual) {
            $this->session->forget($customer);
            $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.manual_fallback', [
                'invoice' => $order->invoice_number,
            ]));

            return true;
        }

        /** @var Payment $payment */
        $payment = $submission->payment;
        if ($submission->dispatchFailed) {
            $this->respondToResult(
                $customer,
                $chatId,
                $order,
                $payment->fresh(),
                BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: true),
                allowRetryButtons: false,
                submittedOrderId: $candidate,
            );

            return true;
        }

        $this->respondIfStillChecking($customer, $chatId, $order, $payment, $candidate);

        return true;
    }

    public function retryOrderId(string $paymentId, string $orderId, int $attemptNumber, int $retryNumber, int $submissionVersion = 0): void
    {
        $payment = Payment::query()->with('order.customer')->find($paymentId);
        $order = $payment?->order;
        $customer = $order?->customer;

        if (! $payment || ! $order || ! $customer) {
            return;
        }

        $storedOrderId = trim((string) $payment->binance_order_id);
        if ($orderId !== '' && $storedOrderId !== $orderId) {
            return;
        }

        $decision = $this->verification->retryOrderId($payment, $attemptNumber, $retryNumber, $submissionVersion);
        if ($decision->result->outcome === BinanceVerificationOutcome::StaleSubmission) {
            return;
        }

        if ($decision->shouldRetry()) {
            $this->queue->dispatchOrderId($payment, $submissionVersion, $retryNumber + 1, $decision->delaySeconds);

            return;
        }

        $payment->refresh();
        $order->refresh();
        $this->session->forget($customer);
        $this->respondToResult(
            $customer,
            (string) $customer->telegram_id,
            $order,
            $payment,
            $decision->result,
            false,
            $storedOrderId,
            $decision->terminal
                && in_array($decision->result->outcome, [BinanceVerificationOutcome::NotFound, BinanceVerificationOutcome::ProviderError], true),
        );
    }

    public function cancelPrompt(Customer $customer): void
    {
        $this->session->forget($customer);
    }

    public function respondToResult(Customer $customer, string $chatId, Order $order, Payment $payment, BinanceVerificationResult $result, bool $allowRetryButtons, ?string $submittedOrderId = null, bool $finalRetry = false): void
    {
        $amount = $this->formatUsdt((int) $payment->amount);
        $verificationMode = $this->verification->status($payment)->mode;
        switch ($result->outcome) {
            case BinanceVerificationOutcome::Confirmed:
                $this->session->forget($customer);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.confirmed', [
                    'invoice' => $order->invoice_number,
                    'amount' => $amount,
                ]));
                break;

            case BinanceVerificationOutcome::AlreadyPaid:
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.already_paid', [
                    'invoice' => $order->invoice_number,
                ]));
                break;

            case BinanceVerificationOutcome::NotFound:
                if ($payment->binance_verification_mode === 'amount_unique') {
                    $this->bot->sendMessage(
                        $chatId,
                        $this->translate($customer, 'payment.binance.not_found_amount', [
                            'invoice' => $order->invoice_number,
                            'amount' => $amount,
                        ]),
                        $allowRetryButtons ? [
                            'inline_keyboard' => [[
                                [
                                    'text' => $this->translate($customer, 'payment.action.paid'),
                                    'callback_data' => 'binance:verify:'.$order->id,
                                ],
                            ]],
                        ] : [],
                    );

                    break;
                }

                if ($finalRetry) {
                    $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                    $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.not_found_order_id_final', [
                        'invoice' => $order->invoice_number,
                    ]));

                    break;
                }

                $this->bot->sendMessage(
                    $chatId,
                    $this->translate($customer, 'payment.binance.not_found_order_id', [
                        'invoice' => $order->invoice_number,
                    ]),
                    $allowRetryButtons ? [
                        'inline_keyboard' => [[
                            [
                                'text' => $this->translate($customer, 'payment.action.change_order_id'),
                                'callback_data' => 'binance:orderid:'.$order->id,
                            ],
                        ]],
                    ] : [],
                );
                break;

            case BinanceVerificationOutcome::Mismatch:
            case BinanceVerificationOutcome::TransactionAlreadyUsed:
            case BinanceVerificationOutcome::OrderNotPending:
                $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.manual_review', [
                    'invoice' => $order->invoice_number,
                ]));
                break;

            case BinanceVerificationOutcome::NotConfigured:
                $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.not_configured'));
                break;

            case BinanceVerificationOutcome::IncompleteWindow:
                $this->bot->sendMessage($chatId, $this->translate($customer, $verificationMode === 'amount_unique'
                    ? 'payment.binance.incomplete_amount'
                    : 'payment.binance.incomplete_order_id'));
                break;

            case BinanceVerificationOutcome::ProviderError:
                if ($result->retryable) {
                    if ($finalRetry) {
                        $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                        $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.retry_final', [
                            'invoice' => $order->invoice_number,
                        ]));

                        break;
                    }

                    $this->bot->sendMessage($chatId, $this->translate($customer, $verificationMode === 'amount_unique'
                        ? 'payment.binance.retry_amount'
                        : 'payment.binance.retry_order_id'));
                    break;
                }

                $this->escalator->notify($order, $payment, $result, $submittedOrderId);
                $this->bot->sendMessage($chatId, $this->translate($customer, 'payment.binance.provider_error'));
                break;

            case BinanceVerificationOutcome::StaleSubmission:
                break;
        }
    }

    /** @return array{0: ?Order, 1: ?Payment} */
    private function findVerifiableOrder(Customer $customer, string $orderId): array
    {
        $order = $customer->orders()->with('payments')->whereKey($orderId)->first();

        $payment = $order?->payments
            ->first(fn (Payment $payment): bool => $payment->provider === 'manual' && $payment->method === 'BINANCE_PAY_ID');

        return [$order, $payment];
    }

    private function amountUniqueGuardMessage(Customer $customer, Order $order, Payment $payment): ?string
    {
        if ($payment->status === PaymentStatus::Paid) {
            return $this->translate($customer, 'payment.binance.already_paid', [
                'invoice' => $order->invoice_number,
            ]);
        }

        if ($order->order_status !== OrderStatus::PendingPayment || $payment->status !== PaymentStatus::Pending) {
            return $this->translate($customer, 'payment.binance.not_pending', [
                'invoice' => $order->invoice_number,
            ]);
        }

        $verificationStatus = $this->verification->status($payment);
        if ($verificationStatus->mode !== 'amount_unique' || ! $verificationStatus->automatic) {
            return $this->translate($customer, 'payment.binance.amount_unique_unavailable', [
                'invoice' => $order->invoice_number,
            ]);
        }

        if ($verificationStatus->closed()) {
            return $this->translate($customer, 'payment.binance.verification_ended', [
                'invoice' => $order->invoice_number,
            ]);
        }

        return null;
    }

    private function guardMessage(Customer $customer, Order $order, Payment $payment): ?string
    {
        if ($payment->status === PaymentStatus::Paid) {
            return $this->translate($customer, 'payment.binance.already_paid', [
                'invoice' => $order->invoice_number,
            ]);
        }

        if ($order->order_status !== OrderStatus::PendingPayment || $payment->status !== PaymentStatus::Pending) {
            return $this->translate($customer, 'payment.binance.not_pending', [
                'invoice' => $order->invoice_number,
            ]);
        }

        $verificationStatus = $this->verification->status($payment);
        if ($verificationStatus->mode !== 'order_id') {
            return $this->translate($customer, 'payment.binance.order_id_unavailable', [
                'invoice' => $order->invoice_number,
            ]);
        }

        if ($verificationStatus->closed()) {
            return $this->translate($customer, 'payment.binance.verification_ended', [
                'invoice' => $order->invoice_number,
            ]);
        }

        return null;
    }

    private function respondIfStillChecking(Customer $customer, string $chatId, Order $order, Payment $payment, string $candidate): void
    {
        $payment->refresh();
        if ($payment->status === PaymentStatus::Paid) {
            return;
        }

        $verificationStatus = $this->verification->status($payment);
        if ($verificationStatus->withinGrace
            && in_array($verificationStatus->outcome, [
                BinanceVerificationOutcome::IncompleteWindow,
                BinanceVerificationOutcome::NotFound,
                BinanceVerificationOutcome::ProviderError,
            ], true)) {
            $this->respondToResult(
                $customer,
                $chatId,
                $order,
                $payment,
                BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError, retryable: true),
                allowRetryButtons: false,
                submittedOrderId: $candidate,
            );

            return;
        }

        if ($verificationStatus->outcome !== BinanceVerificationOutcome::IncompleteWindow) {
            return;
        }

        $this->respondToResult(
            $customer,
            $chatId,
            $order,
            $payment,
            BinanceVerificationResult::of(BinanceVerificationOutcome::ProviderError),
            allowRetryButtons: false,
            submittedOrderId: $candidate,
        );
    }

    private function formatUsdt(int $amountMicro): string
    {
        return rtrim(rtrim(number_format($amountMicro / 1_000_000, 6, '.', ''), '0'), '.');
    }

    /**
     * @param  array<string, scalar|null>  $replace
     */
    private function translate(Customer $customer, string $key, array $replace = []): string
    {
        return $this->locale->translate($customer, $key, $replace);
    }
}
