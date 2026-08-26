<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Services\CredentialDeliveryFormatter;
use App\Services\Credentials\CredentialDelivery;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\KlikQris\KlikQrisCallbackProcessor;
use App\Services\KlikQris\KlikQrisNativeQrisService;
use App\Services\KlikQris\KlikQrisProviderReadiness;
use App\Services\KlikQris\KlikQrisTransaction;
use App\Support\PaymentProvider;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class TelegramOrderIntents
{
    public function __construct(
        private TelegramBotClient $bot,
        private KlikQrisNativeQrisService $klikQrisNativeQris,
        private KlikQrisCallbackProcessor $klikQrisProcessor,
        private KlikQrisProviderReadiness $klikQrisReadiness,
        private TelegramKlikQrisRecoveryEscalator $klikQrisAlerts,
        private TelegramLocale $locale,
        private CredentialDeliveryFormatter $credentialFormatter,
        private CredentialDeliveryPresentation $credentialPresentation,
        private TelegramCredentialDeliveryRenderer $credentialRenderer,
        private TelegramMessageChunker $messageChunker,
    ) {}

    public function sendLatestOrderStatus(Customer $customer, string $chatId): void
    {
        $order = $customer->orders()->latest()->first();
        if (! $order) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'order.status.empty'));

            return;
        }

        $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'order.status.message', [
            'invoice' => $order->invoice_number,
            'order_status' => $this->locale->orderStatus($customer, $order->order_status),
            'payment_status' => $this->locale->paymentStatus($customer, $order->payment_status),
        ]));
    }

    public function sendOrderHistory(Customer $customer, string $chatId): void
    {
        $orders = $customer->orders()->latest()->limit(5)->get();
        if ($orders->isEmpty()) {
            $this->bot->sendMessage(
                $chatId,
                $this->locale->translate($customer, 'order.history.empty'),
                ['inline_keyboard' => [[[
                    'text' => $this->locale->translate($customer, 'order.history.browse_button'),
                    'callback_data' => 'katalog',
                ]]]],
            );

            return;
        }

        $lines = [
            $this->locale->translate($customer, 'order.history.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'order.history.instruction'),
        ];
        $keyboard = [];
        foreach ($orders as $i => $order) {
            $lines[] = ($i + 1).'. '.$order->invoice_number
                .' · '.$this->locale->orderStatus($customer, $order->order_status)
                .' / '.$this->locale->paymentStatus($customer, $order->payment_status)
                .' · Rp '.number_format($order->total_amount, 0, ',', '.')
                .' · '.$this->locale->formatShortDate($customer, $order->created_at);
            $keyboard[] = [[
                'text' => $this->locale->translate($customer, 'order.history.detail_button').$order->invoice_number,
                'callback_data' => 'order:'.$order->invoice_number,
            ]];
        }

        $this->bot->sendMessage($chatId, implode("\n", $lines), ['inline_keyboard' => $keyboard]);
    }

    public function sendOrderDetail(Customer $customer, string $chatId, string $invoice): void
    {
        $order = $customer->orders()
            ->where('invoice_number', $invoice)
            ->with([
                'orderItems.stockItems',
                'orderItems.product',
                'payments' => fn ($query) => $query->latest(),
            ])
            ->first();
        if (! $order) {
            $this->bot->sendMessage($chatId, $this->locale->translate($customer, 'order.detail.not_found'));

            return;
        }

        $payment = $order->payments->first();
        $refreshNotice = $this->refreshProviderPaymentStatus($customer, $order, $payment);
        if ($refreshNotice !== null) {
            $order = $order->fresh([
                'orderItems.stockItems',
                'orderItems.product',
                'payments' => fn ($query) => $query->latest(),
            ]);
            $payment = $order->payments->first();
        }
        $paymentStatus = $payment?->status ?? $order->payment_status;
        $orderStatusLabel = $this->locale->orderStatus($customer, $order->order_status);
        $paymentStatusLabel = $this->locale->paymentStatus($customer, $paymentStatus);
        $lines = [
            $this->locale->translate($customer, 'order.detail.title'),
            '━━━━━━━━━━━━━━',
            $this->locale->translate($customer, 'order.detail.invoice', ['invoice' => $order->invoice_number]),
            $this->locale->translate($customer, 'order.detail.status', [
                'order_status' => $orderStatusLabel,
                'payment_status' => $paymentStatusLabel,
            ]),
            $this->locale->translate($customer, 'order.detail.created', [
                'date' => $this->locale->formatDate($customer, $order->created_at),
            ]),
            '',
            $this->locale->translate($customer, 'order.detail.items'),
        ];
        foreach ($order->orderItems as $item) {
            $lines[] = '- '.$item->product_name.' x '.$item->quantity;
        }
        $lines[] = $this->locale->translate($customer, 'order.detail.total', [
            'amount' => 'Rp '.number_format($order->total_amount, 0, ',', '.'),
        ]);
        if ($payment) {
            $lines[] = $this->locale->translate($customer, 'order.detail.payment', [
                'method' => $this->locale->paymentMethod($customer, $payment),
                'status' => $this->locale->paymentStatus($customer, $payment->status),
            ]);
            if ($payment->paid_at) {
                $lines[] = $this->locale->translate($customer, 'order.detail.paid', [
                    'date' => $this->locale->formatDate($customer, $payment->paid_at),
                ]);
            }
        }
        if (($order->order_status === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending)
            && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = $this->locale->translate($customer, 'order.detail.deadline', [
                'date' => $this->locale->formatDate($customer, $payment?->expired_at ?? $order->expires_at),
            ]);
        }
        if (in_array($paymentStatus, [PaymentStatus::Expired, PaymentStatus::Failed], true)
            && ($payment?->expired_at || $order->expires_at)) {
            $lines[] = $this->locale->translate($customer, 'order.detail.expired', [
                'date' => $this->locale->formatDate($customer, $payment?->expired_at ?? $order->expires_at),
            ]);
        }
        if ($refreshNotice !== null) {
            $lines[] = '';
            $lines[] = $refreshNotice;
        }
        $delivery = $this->credentialPresentation->forCustomer($customer, $order, $payment);
        if ($this->hasStructuredAccount($delivery)) {
            $lines[] = '';
            $lines[] = $this->locale->translate($customer, 'order.detail.account_details');
            $units = [
                ...$this->unitsFromLines($lines),
                ...$this->credentialRenderer->accountUnits(
                    $customer,
                    $delivery,
                    'order.detail.otp_button',
                    'order.detail.delivery_note',
                ),
                new TelegramMessageUnit($this->orderInstruction($customer, $order, $payment)),
            ];
        } else {
            $accountLines = $this->accountSecretLines($customer, $delivery);
            if ($accountLines !== []) {
                $lines[] = '';
                $lines[] = $this->locale->translate($customer, 'order.detail.account_details');
                array_push($lines, ...$accountLines);
            }
            $lines[] = '';
            $lines[] = $this->orderInstruction($customer, $order, $payment);
            $units = $this->unitsFromLines($lines, withCredentialEntities: true);
        }

        $lastResponse = null;
        foreach ($units as $unit) {
            $lastResponse = $this->bot->sendMessage(
                $chatId,
                $unit->text(),
                $unit->replyMarkup(),
                $unit->options(),
            );
        }

        $messageId = data_get($lastResponse, 'result.message_id');
        if ($payment?->provider === PaymentProvider::KLIKQRIS && $paymentStatus === PaymentStatus::Pending && is_numeric($messageId)) {
            Order::query()->whereKey($order->id)->update([
                'buyer_order_detail_message_chat_id' => $chatId,
                'buyer_order_detail_message_id' => (int) $messageId,
                'buyer_order_detail_message_deleted_at' => null,
                'buyer_order_detail_message_deletion_started_at' => null,
                'buyer_order_detail_message_deletion_token' => null,
            ]);
        }
    }

    public function orderInstruction(Customer $customer, Order $order, ?Payment $payment): string
    {
        $orderStatus = $order->order_status;
        $paymentStatus = $payment?->status ?? $order->payment_status;
        if ($order->hasOpenLatePaymentReview()) {
            return $this->locale->translate(
                $customer,
                $orderStatus === OrderStatus::Cancelled
                    ? 'order.guidance.manual_review_cancelled'
                    : 'order.guidance.manual_review',
            );
        }
        if ($paymentStatus === PaymentStatus::Refunded) {
            return $this->locale->translate($customer, 'order.guidance.refunded');
        }
        if ($orderStatus === OrderStatus::Expired || $orderStatus === OrderStatus::Cancelled) {
            if ($paymentStatus === PaymentStatus::Paid) {
                return $this->locale->translate(
                    $customer,
                    $orderStatus === OrderStatus::Cancelled
                        ? 'order.guidance.late_payment_cancelled'
                        : 'order.guidance.late_payment',
                );
            }
            if ($paymentStatus === PaymentStatus::Failed) {
                return $this->locale->translate($customer, 'order.guidance.payment_failed');
            }
            if ($orderStatus === OrderStatus::Expired) {
                return $this->locale->translate($customer, 'order.guidance.expired');
            }

            return $this->locale->translate($customer, 'order.guidance.cancelled');
        }
        if ($paymentStatus === PaymentStatus::Failed) {
            return $this->locale->translate($customer, 'order.guidance.payment_failed');
        }
        if ($paymentStatus === PaymentStatus::Expired) {
            return $this->locale->translate($customer, 'order.guidance.expired');
        }
        if ($orderStatus === OrderStatus::PendingPayment || $paymentStatus === PaymentStatus::Pending) {
            return $this->locale->translate($customer, 'order.guidance.pending');
        }
        if ($orderStatus === OrderStatus::Processing) {
            return $this->locale->translate($customer, 'order.guidance.processing');
        }
        if ($orderStatus === OrderStatus::Completed) {
            return $this->locale->translate($customer, 'order.guidance.completed');
        }
        if ($paymentStatus === PaymentStatus::Paid) {
            return $this->locale->translate($customer, 'order.guidance.processing');
        }

        return $this->locale->translate($customer, 'order.guidance.support');
    }

    private function refreshProviderPaymentStatus(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        return match (strtolower(trim((string) $payment?->provider))) {
            PaymentProvider::KLIKQRIS => $this->refreshEligibleKlikQrisPayment($customer, $order, $payment),
            default => null,
        };
    }

    private function refreshEligibleKlikQrisPayment(Customer $customer, Order $order, ?Payment $payment): ?string
    {
        if (! $payment
            || $payment->provider !== PaymentProvider::KLIKQRIS
            || ! in_array($payment->status, [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Failed], true)
            || blank($payment->merchant_ref)
            || strtolower(trim((string) $payment->provider_payment_method)) !== 'qris'
            || $payment->provider_total_payment === null
            || ! $this->klikQrisReadiness->checkout()->isReady()) {
            return null;
        }

        $key = 'klikqris:status-refresh:'.$customer->id.':'.$payment->id;
        $maxAttempts = max(1, (int) config('services.klikqris.status_refresh_rate_limit_per_minute', 3));
        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);

            return $this->locale->translate($customer, 'order.refresh.rate_limited', ['seconds' => $seconds]);
        }

        RateLimiter::hit($key, 60);

        try {
            $event = null;
            $result = $this->klikQrisNativeQris->reconcile(
                $payment,
                function (Payment $payment, KlikQrisTransaction $transaction) use (&$event): void {
                    $event = $this->klikQrisProcessor->processReconciliationForPayment($payment, $transaction);
                },
            );
        } catch (Throwable $e) {
            report($e);
            $this->klikQrisAlerts->notifyFor($payment);

            return $this->locale->translate($customer, 'order.refresh.unavailable');
        }

        if ($result !== null) {
            $this->klikQrisAlerts->notifyFor($payment);
        }

        if ($event?->processed_at !== null) {
            return $this->locale->translate($customer, 'order.refresh.verified');
        }

        return $this->locale->translate($customer, 'order.refresh.not_paid');
    }

    private function accountSecretLines(Customer $customer, CredentialDelivery $delivery): array
    {
        $lines = [];
        foreach ($delivery->groups() as $group) {
            $lines[] = $group->productName();
            foreach ($group->accounts() as $account) {
                $lines[] = $account->number().'. '.$account->text();

                if ($account->isStructured()
                    && $account->tutorial() !== null
                    && $account->tutorial() !== $group->defaultTutorial()) {
                    $lines[] = $this->locale->translate($customer, 'order.detail.delivery_note');
                    $lines[] = $account->tutorial();
                }
            }
            if ($group->defaultTutorial() !== null) {
                $lines[] = $this->locale->translate($customer, 'order.detail.delivery_note');
                $lines[] = $group->defaultTutorial();
            }
        }

        return $lines;
    }

    /** @return list<TelegramMessageUnit> */
    private function unitsFromLines(array $lines, bool $withCredentialEntities = false): array
    {
        return array_map(
            fn (string $chunk): TelegramMessageUnit => new TelegramMessageUnit(
                text: $chunk,
                entities: $withCredentialEntities ? $this->credentialFormatter->codeEntities($chunk) : [],
            ),
            $this->messageChunker->fromLines($lines),
        );
    }

    private function hasStructuredAccount(CredentialDelivery $delivery): bool
    {
        foreach ($delivery->groups() as $group) {
            foreach ($group->accounts() as $account) {
                if ($account->isStructured()) {
                    return true;
                }
            }
        }

        return false;
    }
}
