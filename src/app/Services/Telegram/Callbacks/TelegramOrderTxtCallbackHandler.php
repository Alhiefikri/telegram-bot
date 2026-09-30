<?php

namespace App\Services\Telegram\Callbacks;

use App\Models\Order;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Services\Telegram\TelegramBotClient;
use App\Services\Telegram\TelegramCallbackContext;
use App\Services\Telegram\TelegramCallbackHandler;
use App\Services\Telegram\TelegramCallbackStage;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\Telegram\TelegramProductNameRenderer;
use App\Services\Telegram\TelegramReceiptObservability;
use App\Services\Telegram\TelegramReceiptTxtConfirmationSession;
use App\Services\Telegram\TelegramReceiptTxtRateLimiter;
use App\Support\DogfoodTiming;

/**
 * Paid Order TXT export callbacks.
 *
 * The TXT button is itself the explicit authorization to create the
 * credential-bearing document. Credential values are never hydrated or
 * rendered inside the callback path; the callback only creates the durable
 * outbound effect and returns.
 */
class TelegramOrderTxtCallbackHandler implements TelegramCallbackHandler
{
    private const MAX_FALLBACK_CONFIRMATION_ORDERS = 50;

    /** @var list<string> */
    private const CALLBACK_PATTERNS = [
        'txt:request:' => '/^txt:request:([0-9a-fA-F-]{36})$/D',
        'txt:send:' => '/^txt:send:([0-9a-fA-F]{32})$/D',
        'txt:cancel:' => '/^txt:cancel:([0-9a-fA-F]{32})$/D',
    ];

    public function __construct(
        private TelegramBotClient $bot,
        private TelegramLocale $locale,
        private TelegramOutboundEffectDispatcher $dispatcher,
        private TelegramOutboundEffectService $effects,
        private CredentialDeliveryEligibility $eligibility,
        private TelegramReceiptTxtConfirmationSession $confirmation,
        private TelegramReceiptObservability $observability,
        private TelegramReceiptTxtRateLimiter $rateLimiter,
        private TelegramProductNameRenderer $productNames,
    ) {}

    public function stage(): TelegramCallbackStage
    {
        return TelegramCallbackStage::Customer;
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
        [$order, $rejectionCategory] = $this->eligibleOrderDecision($context, $orderId);

        if ($order === null) {
            $this->observability->txtRequestRejected(
                $rejectionCategory ?? 'order_unavailable',
                $context->customer,
                callbackQueryId: $context->callbackQueryId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        if (! $this->eligibility->hasAllocatedCredentials($order)) {
            $this->observability->txtRequestRejected(
                'credential_projection_unavailable',
                $context->customer,
                $order,
                $context->callbackQueryId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $this->queueFile($context, $order, bin2hex(random_bytes(16)));
    }

    private function handleSendFile(TelegramCallbackContext $context, string $nonce): void
    {
        $order = $this->consumeBoundOrder($context, $nonce);
        if ($order === null) {
            $this->observability->txtRequestStale(
                $context->customer,
                $context->callbackQueryId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.stale'),
            );

            return;
        }

        [$eligibleOrder, $rejectionCategory] = $this->eligibleOrderDecision($context, (string) $order->getKey());
        if (! $eligibleOrder instanceof Order) {
            $this->observability->txtRequestRejected(
                $rejectionCategory ?? 'order_unavailable',
                $context->customer,
                callbackQueryId: $context->callbackQueryId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        if (! $this->eligibility->hasAllocatedCredentials($eligibleOrder)) {
            $this->observability->txtRequestRejected(
                'credential_projection_unavailable',
                $context->customer,
                $eligibleOrder,
                $context->callbackQueryId,
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $this->queueFile($context, $eligibleOrder, $nonce);
    }

    private function queueFile(
        TelegramCallbackContext $context,
        Order $order,
        string $requestNonce,
    ): void {
        if (! $this->rateLimiter->allow($context->customer, $order)) {
            $this->observability->txtRequestRejected(
                'download_window',
                $context->customer,
                $order,
                $context->callbackQueryId,
                'rate_limit',
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $effect = $this->effects->recordReceiptTxtDownload(
            $order,
            $requestNonce,
            $context->customer,
            [
                'origin_message_id' => $context->messageId,
                'correlation_id' => DogfoodTiming::correlation($context->callbackQueryId),
            ],
        );

        if ($effect === null) {
            $this->observability->txtRequestRejected(
                'effect_not_created',
                $context->customer,
                $order,
                $context->callbackQueryId,
                'effect',
            );
            $this->bot->answerCallbackQuery(
                $context->callbackQueryId,
                $this->locale->translate($context->customer, 'receipt.txt.unavailable'),
            );

            return;
        }

        $this->observability->txtRequestAccepted(
            $effect,
            $context->customer,
            $order,
            $context->callbackQueryId,
        );
        $this->bot->answerCallbackQuery(
            $context->callbackQueryId,
            $this->locale->translate($context->customer, 'receipt.txt.request_started'),
        );
        $this->dispatcher->dispatch($effect);
    }

    private function handleCancel(TelegramCallbackContext $context, string $nonce): void
    {
        if ($this->consumeBoundOrder($context, $nonce) === null) {
            $this->observability->txtRequestStale(
                $context->customer,
                $context->callbackQueryId,
            );
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
        $indexedOrderId = $this->confirmation->orderIdForNonce($context->customer, $context->chatId, $nonce);
        if ($indexedOrderId !== null) {
            $confirmation = $this->confirmation->consume(
                $context->customer,
                $context->chatId,
                $indexedOrderId,
                $nonce,
            );
            if ($confirmation === null) {
                return null;
            }

            return Order::query()
                ->with(['customer', 'payments', 'orderItems'])
                ->whereKey($indexedOrderId)
                ->first();
        }

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
            ->latest('created_at')
            ->limit(self::MAX_FALLBACK_CONFIRMATION_ORDERS)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    /** @return array{0: ?Order, 1: ?string} */
    private function eligibleOrderDecision(TelegramCallbackContext $context, string $orderId): array
    {
        if ((string) $context->chatId !== trim((string) $context->customer->telegram_id)) {
            return [null, 'chat_mismatch'];
        }

        $order = Order::query()
            ->with(['customer', 'payments', 'orderItems'])
            ->whereKey($orderId)
            ->first();

        if ($order === null) {
            return [null, 'order_unavailable'];
        }

        $rejectionReason = $this->eligibility->rejectionReason($order, $context->customer);
        if ($rejectionReason !== null) {
            return [null, $rejectionReason];
        }

        return [$order, null];
    }

    /** @return array<string, string> */
    private function summaryLines(Order $order): array
    {
        $lines = [];
        foreach ($order->orderItems->sortBy('id')->values() as $item) {
            $lines[] = $this->productNames->render(
                (string) $item->product_name,
                $item->product_icon,
                $item->product_custom_emoji_id,
            );
        }

        $reference = trim((string) $order->invoice_number);

        return [
            'product_lines' => implode("\n", $lines),
            'order_reference' => $reference !== '' ? $reference : '#'.(string) $order->getKey(),
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
