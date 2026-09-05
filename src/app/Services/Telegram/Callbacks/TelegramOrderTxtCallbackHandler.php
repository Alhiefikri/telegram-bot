<?php

namespace App\Services\Telegram\Callbacks;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Credentials\CredentialDeliveryPresentation;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramReceiptTxtConfirmationSession;
use Throwable;

/**
 * Paid Order TXT export confirmation and authorization callbacks.
 *
 * The confirmation is deliberately small and credential-free. Credential
 * values are never hydrated or rendered inside the callback path; the
 * Send-file action only creates the durable outbound effect and returns.
 */
class TelegramOrderTxtCallbackHandler implements TelegramCallbackHandler
{
    /** @var list<string> */
    private const CALLBACK_PATTERNS = [
        'txt:request:' => '/^txt:request:([0-9a-fA-F-]{36})$/D',
        'txt:send:' => '/^txt:send:([0-9a-fA-F]{32})$/D',
        'txt:cancel:' => '/^txt:cancel:([0-9a-fA-F]{32})$/D',
    ];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramOutboundEffectService $effects,
        private CredentialDeliveryPresentation $presentation,
        private TelegramReceiptTxtConfirmationSession $confirmation,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::AfterCommunityGate;
    }

    public function allowsNonPrivateChat(TelegramCallbackContext $context): bool
    {
        return false;
    }

    public function supports(TelegramCallbackContext $context): bool
    {
        foreach (self::CALLBACK_PATTERNS as $prefix => $pattern) {
            if (str_starts_with($context->data, $prefix)
                && preg_match($pattern, $context->data) === 1) {
                return true;
            }
        }

        return false;
    }

    public function handle(TelegramCallbackContext $context): bool
    {
        if (preg_match(self::CALLBACK_PATTERNS['txt:request:'], $context->data, $matches) === 1) {
            $this->handleRequest($context, $matches[1]);

            return true;
        }

        if (preg_match(self::CALLBACK_PATTERNS['txt:send:'], $context->data, $matches) === 1) {
            $this->handleSendFile($context, $matches[1]);

            return true;
        }

        if (preg_match(self::CALLBACK_PATTERNS['txt:cancel:'], $context->data, $matches) === 1) {
            $this->handleCancel($context, $matches[1]);

            return true;
        }

        return false;
    }

    private function handleRequest(TelegramCallbackContext $context, string $orderId): void
    {
        $order = $this->customerOwnedEligibleOrder($context, $orderId);

        if ($order === null || ! $this->hasCredentialProjection($context, $order)) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $confirmation = $this->confirmation->start(
            $context->customer,
            $context->chatId,
            (string) $order->getKey(),
            $this->locale->code($context->customer),
        );

        $this->bot->answerCallbackQuery($context->callbackQueryId);
        $this->bot->sendMessage(
            $context->chatId,
            $this->confirmationMessage($context, $order),
            [
                'inline_keyboard' => [[
                    [
                        'text' => $this->locale->translate($context->customer, 'receipt.txt.send_file_button'),
                        'callback_data' => 'txt:send:'.$confirmation['nonce'],
                    ],
                    [
                        'text' => $this->locale->translate($context->customer, 'receipt.txt.cancel_button'),
                        'callback_data' => 'txt:cancel:'.$confirmation['nonce'],
                    ],
                ]],
            ],
        );
    }

    private function handleSendFile(TelegramCallbackContext $context, string $nonce): void
    {
        $order = $this->consumeBoundOrder($context, $nonce);
        if ($order === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.stale'),
            );

            return;
        }

        if (! $this->customerOwnedEligibleOrder($context, (string) $order->getKey()) instanceof Order
            || ! $this->hasCredentialProjection($context, $order)) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $effect = $this->effects->recordReceiptTxtDownload(
            $order,
            $nonce,
            $context->customer,
            [
                'origin_message_id' => $context->messageId,
                'correlation_id' => $context->callbackQueryId,
            ],
        );

        if ($effect === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->locale->translate($context->customer, 'receipt.txt.request_started'),
        );
    }

    private function handleCancel(TelegramCallbackContext $context, string $nonce): void
    {
        if ($this->consumeBoundOrder($context, $nonce) === null) {
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.stale'),
            );

            return;
        }

        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->locale->translate($context->customer, 'receipt.txt.cancelled'),
        );
    }

    /**
     * Atomically consume the single confirmation the nonce could belong to.
     * The confirmation is keyed by Telegram chat and Order, so the nonce
     * itself cannot be used to enumerate another chat's confirmations.
     */
    private function consumeBoundOrder(TelegramCallbackContext $context, string $nonce): ?Order
    {
        foreach ($this->ordersFor($context) as $orderId) {
            $confirmation = $this->confirmation->consume($context->customer, $context->chatId, $orderId, $nonce);
            if ($confirmation === null) {
                continue;
            }

            $order = Order::query()->with(['customer', 'payments', 'orderItems'])->whereKey($orderId)->first();
            if ($order === null) {
                continue;
            }

            return $order;
        }

        return null;
    }

    /** @return list<string> */
    private function ordersFor(TelegramCallbackContext $context): array
    {
        return $context->customer->orders()
            ->whereIn('order_status', [OrderStatus::PendingPayment->value, OrderStatus::Processing->value, OrderStatus::Completed->value])
            ->where('payment_status', PaymentStatus::Paid->value)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function customerOwnedEligibleOrder(TelegramCallbackContext $context, string $orderId): ?Order
    {
        $order = Order::query()
            ->with(['customer', 'payments', 'orderItems'])
            ->whereKey($orderId)
            ->where('customer_id', $context->customer->getKey())
            ->first();

        if ($order === null) {
            return null;
        }

        if (! $this->presentationEligible($order)) {
            return null;
        }

        return $order;
    }

    private function hasCredentialProjection(TelegramCallbackContext $context, Order $order): bool
    {
        try {
            return ! $this->presentation->forCustomer($context->customer, $order)->isEmpty();
        } catch (Throwable) {
            return false;
        }
    }

    private function presentationEligible(Order $order): bool
    {
        if ($order->payment_status !== PaymentStatus::Paid
            || ! in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)) {
            return false;
        }

        if ($order->hasOpenLatePaymentReview()) {
            return false;
        }

        $payment = $order->payments
            ->sortByDesc(fn ($candidate): array => [
                $candidate->created_at?->getTimestamp() ?? 0,
                (string) $candidate->getKey(),
            ])
            ->first();

        return $payment !== null
            && (string) $payment->order_id === (string) $order->getKey()
            && $payment->status === PaymentStatus::Paid
            && $payment->paid_at !== null;
    }

    /** @return array<string, string> */
    private function summaryLines(Order $order): array
    {
        $lines = [];
        foreach ($order->orderItems->sortBy('id')->values() as $item) {
            $lines[] = '📦 '.$item->product_name;
        }

        $reference = trim((string) $order->invoice_number);

        return [
            'product_lines' => implode("\n", $lines),
            'order_reference' => $reference !== '' ? $reference : '#'.substr((string) $order->getKey(), 0, 8),
            'item_count' => (string) $this->sumQuantities($order),
        ];
    }

    private function sumQuantities(Order $order): int
    {
        $count = 0;
        foreach ($order->orderItems as $item) {
            $quantity = (int) $item->getAttribute('quantity');
            $count += $quantity;
        }

        return $count;
    }

    private function confirmationMessage(TelegramCallbackContext $context, Order $order): string
    {
        $summary = $this->summaryLines($order);

        return $this->locale->translate($context->customer, 'receipt.txt.confirmation', [
            'product' => $summary['product_lines'],
            'order' => $summary['order_reference'],
            'items' => $summary['item_count'],
        ]);
    }
}
