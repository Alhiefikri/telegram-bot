<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\SalesProofPost;
use App\Models\StockNotificationRecipient;
use App\Models\StoreChannelSetting;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Credentials\CredentialDeliveryEligibility;
use App\Support\PaymentProvider;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Materializes durable effect rows and owns operator-facing lookup/resend
 * history. Delivery state transitions belong exclusively to the ledger
 * protocol; family delivery adapters do not call this class to claim or
 * finalize work.
 */
class TelegramOutboundEffectService
{
    public const TYPE_MANUAL_CHECKOUT = TelegramOutboundEffectType::ManualCheckoutInstructions->value;

    public const TYPE_KLIKQRIS_CHECKOUT = TelegramOutboundEffectType::KlikQrisCheckoutInstructions->value;

    public const TYPE_SAKURUPIAH_CHECKOUT = TelegramOutboundEffectType::SakurupiahCheckoutInstructions->value;

    public const TYPE_PAKASIR_CHECKOUT = TelegramOutboundEffectType::PakasirCheckoutInstructions->value;

    public const TYPE_BINANCE_CHECKOUT = TelegramOutboundEffectType::BinanceCheckoutInstructions->value;

    public const TYPE_BSC_CHECKOUT = TelegramOutboundEffectType::BscCheckoutInstructions->value;

    public const TYPE_RECEIPT_CREDENTIALS = TelegramOutboundEffectType::ReceiptCredentials->value;

    public const TYPE_RECEIPT_TXT_DOWNLOAD = TelegramOutboundEffectType::ReceiptTxtDownload->value;

    public const TYPE_RECEIPT_ORDER_ACTIONS = TelegramOutboundEffectType::ReceiptOrderActions->value;

    /** Compatibility alias for the shorter panel family name. */
    public const TYPE_RECEIPT_ACTIONS = self::TYPE_RECEIPT_ORDER_ACTIONS;

    public const TYPE_ADMIN_PENDING = TelegramOutboundEffectType::AdminOrderPendingNotification->value;

    public const TYPE_ADMIN_PAID = TelegramOutboundEffectType::AdminOrderPaidNotification->value;

    public const TYPE_ADMIN_EXPIRED = TelegramOutboundEffectType::AdminOrderExpiredNotification->value;

    public const TYPE_ADMIN_BROADCAST = TelegramOutboundEffectType::AdminBroadcastRecipient->value;

    /** Compatibility alias for callers that spell out the recipient unit. */
    public const TYPE_ADMIN_BROADCAST_RECIPIENT = self::TYPE_ADMIN_BROADCAST;

    public const TYPE_MANUAL_PAYMENT_PROOF = TelegramOutboundEffectType::ManualPaymentProof->value;

    public const TYPE_SALES_PROOF = TelegramOutboundEffectType::SalesProofPost->value;

    public const TYPE_STOCK_NOTIFICATION = TelegramOutboundEffectType::StockNotificationRecipient->value;

    /** Compatibility alias for callers that name the delivery unit. */
    public const TYPE_STOCK_NOTIFICATION_RECIPIENT = self::TYPE_STOCK_NOTIFICATION;

    public const TYPE_PRICE_CHANGE_NOTIFICATION = TelegramOutboundEffectType::PriceChangeNotificationRecipient->value;

    /** Compatibility aliases for price notification callers. */
    public const TYPE_PRICE_NOTIFICATION = self::TYPE_PRICE_CHANGE_NOTIFICATION;

    public const TYPE_PRICE_CHANGE_NOTIFICATION_RECIPIENT = self::TYPE_PRICE_CHANGE_NOTIFICATION;

    public const TYPE_PRICE_CHANGE_STORE_CHANNEL = TelegramOutboundEffectType::PriceChangeStoreChannel->value;

    /** Compatibility aliases for callers that use the longer effect names. */
    public const TYPE_ADMIN_ORDER_PENDING_NOTIFICATION = self::TYPE_ADMIN_PENDING;

    public const TYPE_ADMIN_ORDER_PAID_NOTIFICATION = self::TYPE_ADMIN_PAID;

    public const TYPE_ADMIN_ORDER_EXPIRED_NOTIFICATION = self::TYPE_ADMIN_EXPIRED;

    /** Compatibility alias for callers that refer to the receipt family directly. */
    public const TYPE_RECEIPT = self::TYPE_RECEIPT_CREDENTIALS;

    public const TEMPLATE_MANUAL_CHECKOUT = 'manual-payment-v1';

    public const TEMPLATE_KLIKQRIS_CHECKOUT = 'klikqris-payment-v1';

    public const TEMPLATE_SAKURUPIAH_CHECKOUT = 'sakurupiah-payment-v1';

    public const TEMPLATE_PAKASIR_CHECKOUT = 'pakasir-payment-v1';

    public const TEMPLATE_BINANCE_CHECKOUT = 'binance-payment-v1';

    public const TEMPLATE_BSC_CHECKOUT = 'bsc-payment-v1';

    public const TEMPLATE_RECEIPT_CREDENTIALS = 'receipt-credentials-v1';

    /** Legacy invoice-photo template with a separate Product Detail delivery. */
    public const TEMPLATE_RECEIPT_INVOICE = 'receipt-invoice-v2';

    /** New private buyer photo with its complete caption. */
    public const TEMPLATE_RECEIPT_PHOTO_CAPTION = 'receipt-photo-caption-v1';

    /** Explicit pre-send fallback when the invoice image cannot render. */
    public const TEMPLATE_RECEIPT_TEXT_FALLBACK = 'receipt-text-fallback-v1';

    public const TEMPLATE_RECEIPT_TXT_DOWNLOAD = 'receipt-txt-v1';

    public const TEMPLATE_RECEIPT_ORDER_ACTIONS = 'receipt-actions-v1';

    /** Compatibility alias for the shorter panel template name. */
    public const TEMPLATE_RECEIPT_ACTIONS = self::TEMPLATE_RECEIPT_ORDER_ACTIONS;

    public const RECEIPT_ACTIONS_OPT_IN_VERSION = 'v1';

    public const TEMPLATE_ADMIN_ORDER_PENDING = 'admin-order-pending-v1';

    public const TEMPLATE_ADMIN_ORDER_PAID = 'admin-order-paid-v1';

    public const TEMPLATE_ADMIN_ORDER_EXPIRED = 'admin-order-expired-v1';

    public const TEMPLATE_ADMIN_BROADCAST = 'admin-broadcast-v1';

    public const TEMPLATE_MANUAL_PAYMENT_PROOF = 'manual-payment-proof-v1';

    /** Text-only Sales Proof effects materialized before the photo cutover. */
    public const TEMPLATE_SALES_PROOF_LEGACY = 'sales-proof-post-v1';

    /** Photo-capable Sales Proof effects materialized after the invoice cutover. */
    public const TEMPLATE_SALES_PROOF = 'sales-proof-post-v2';

    public const TEMPLATE_STOCK_NOTIFICATION = 'stock-notification-v1';

    public const TEMPLATE_PRICE_CHANGE_NOTIFICATION = 'price-change-notification-v1';

    public const TEMPLATE_PRICE_CHANGE_STORE_CHANNEL = 'price-change-store-channel-v1';

    public const RECEIPT_QUEUE = 'notifications';

    public const RECEIPT_TXT_DOWNLOAD_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::RECEIPT_TXT_DOWNLOAD_MAX_ATTEMPTS;

    public const RECEIPT_ACTIONS_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::RECEIPT_ACTIONS_MAX_ATTEMPTS;

    public const SALES_PROOF_QUEUE = 'notifications';

    public const SALES_PROOF_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::SALES_PROOF_MAX_ATTEMPTS;

    public const STOCK_NOTIFICATION_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::STOCK_NOTIFICATION_MAX_ATTEMPTS;

    public const PRICE_CHANGE_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::PRICE_CHANGE_MAX_ATTEMPTS;

    public const PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS;

    /** @var list<string> */
    private const CHECKOUT_EFFECT_TYPES = [
        self::TYPE_MANUAL_CHECKOUT,
        self::TYPE_KLIKQRIS_CHECKOUT,
        self::TYPE_SAKURUPIAH_CHECKOUT,
        self::TYPE_PAKASIR_CHECKOUT,
        self::TYPE_BINANCE_CHECKOUT,
        self::TYPE_BSC_CHECKOUT,
    ];

    public const ADMIN_BROADCAST_MAX_ATTEMPTS = TelegramOutboundEffectPolicy::ADMIN_BROADCAST_MAX_ATTEMPTS;

    /** @var list<string> */
    private const SALES_PROOF_EFFECT_TYPES = [
        self::TYPE_SALES_PROOF,
    ];

    /** @var list<string> */
    private const REVIEW_STATES = [
        TelegramOutboundEffectState::Unknown->value,
        TelegramOutboundEffectState::ManualReview->value,
    ];

    private const SAFE_REVIEW_METADATA_KEYS = [
        'exception',
        'http_status',
        'reason',
        'telegram_response',
        'delivery_context',
        'duplicate_risk_acknowledged',
        'resolution',
        'resolution_actor_id',
        'resolution_at',
        'unit_role',
        'receipt_chunk_index',
        'receipt_chunk_count',
        'product_count',
        'account_count',
        'caption_length_bucket',
        'continuation_length_bucket',
        'has_photo',
        'has_header',
        'has_footer',
        'render_mode',
        'attachment_type',
        'attachment_present',
        'payment_method',
        'late_evidence',
        'proof_status',
        'delivery_outcome',
        'delivery_method',
        'format',
        'item_count',
        'byte_size_bucket',
        'output_size_bucket',
        'render_duration_bucket',
        'telegram_document_duration_bucket',
        'queue_wait_bucket',
        'effect_completion_duration_bucket',
        'origin_message_id',
        'confirmation_nonce',
        'correlation_id',
        'action_count',
        'txt_available',
        'receipt_group_key',
        'receipt_actions_version',
        'copy_length_bucket',
        'keyboard_length_bucket',
    ];

    public function __construct(
        private readonly TelegramOutboundEffectLedgerProtocol $ledger,
        private readonly CredentialDeliveryEligibility $credentialEligibility,
        private readonly AuditLogger $auditLogger,
        private readonly TelegramReceiptNotifier $receiptNotifier,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramReceiptActionPanel $receiptActionPanel,
        private readonly TelegramReceiptObservability $observability,
    ) {}

    public function recordCheckoutInstructions(Order $order, Payment $payment, ?Customer $customer = null): ?TelegramOutboundEffect
    {
        $customer ??= $order->customer;
        $effectType = self::typeForPayment($payment);

        if ($customer?->telegram_id === null || $effectType === null) {
            return null;
        }

        $effectKey = self::checkoutEffectKey((string) $order->getKey(), $effectType);

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => $effectType,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => TelegramOutboundEffect::CHECKOUT_QUEUE,
                'telegram_method' => $effectType === self::TYPE_BSC_CHECKOUT ? 'sendMessage' : 'sendPhoto',
                'locale' => $customer->locale,
                'template_version' => self::templateVersion($effectType),
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }

    public function recordSakurupiahPresentationRecovery(
        Order $order,
        Payment $payment,
        ?Customer $customer = null,
    ): ?TelegramOutboundEffect {
        $customer ??= $order->customer;
        if ($customer?->telegram_id === null || self::typeForPayment($payment) !== self::TYPE_SAKURUPIAH_CHECKOUT) {
            return null;
        }

        $effectKey = self::sakurupiahPresentationRecoveryEffectKey((string) $payment->getKey());

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => self::checkoutEffectKey((string) $order->getKey(), self::TYPE_SAKURUPIAH_CHECKOUT),
                'sequence' => 2,
                'effect_type' => self::TYPE_SAKURUPIAH_CHECKOUT,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => TelegramOutboundEffect::CHECKOUT_QUEUE,
                'telegram_method' => 'sendPhoto',
                'locale' => $customer->locale,
                'template_version' => self::TEMPLATE_SAKURUPIAH_CHECKOUT,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }

    public function recordKlikQrisPresentationRecovery(
        Order $order,
        Payment $payment,
        ?Customer $customer = null,
    ): ?TelegramOutboundEffect {
        $customer ??= $order->customer;
        if ($customer?->telegram_id === null
            || self::typeForPayment($payment) !== self::TYPE_KLIKQRIS_CHECKOUT
            || $order->order_status !== OrderStatus::PendingPayment
            || $payment->status !== PaymentStatus::Pending) {
            return null;
        }

        $checkoutEffect = TelegramOutboundEffect::query()
            ->where('effect_key', self::checkoutEffectKey((string) $order->getKey(), self::TYPE_KLIKQRIS_CHECKOUT))
            ->first();
        if ($checkoutEffect === null
            || $checkoutEffect->state !== TelegramOutboundEffectState::Sent
            || data_get($checkoutEffect->outcome_metadata, 'delivery_outcome') !== 'retry_notice_sent') {
            return null;
        }

        $effectKey = self::klikQrisPresentationRecoveryEffectKey((string) $payment->getKey());

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => self::checkoutEffectKey((string) $order->getKey(), self::TYPE_KLIKQRIS_CHECKOUT),
                'sequence' => 2,
                'effect_type' => self::TYPE_KLIKQRIS_CHECKOUT,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => TelegramOutboundEffect::CHECKOUT_QUEUE,
                'telegram_method' => 'sendPhoto',
                'locale' => $customer->locale,
                'template_version' => self::TEMPLATE_KLIKQRIS_CHECKOUT,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
    }

    public function materializeCheckoutEffectForOrder(string $orderId, string $effectType): ?TelegramOutboundEffect
    {
        $order = Order::query()->with(['customer', 'payments'])->findOrFail($orderId);
        $payment = $order->payments->first(
            fn (Payment $candidate): bool => self::typeForPayment($candidate) === $effectType,
        );
        if ($payment === null) {
            return null;
        }

        return $this->recordCheckoutInstructions($order, $payment, $order->customer);
    }

    /**
     * Compatibility name retained for the first ledger rollout slice.
     */
    public function recordManualCheckout(Order $order, Payment $payment, ?Customer $customer = null): ?TelegramOutboundEffect
    {
        return $this->recordCheckoutInstructions($order, $payment, $customer);
    }

    public function recordAdminOrderPendingNotification(Order $order, ?string $chatId = null): ?TelegramOutboundEffect
    {
        return $this->recordAdminOrderNotification(
            order: $order,
            effectType: self::TYPE_ADMIN_PENDING,
            templateVersion: self::TEMPLATE_ADMIN_ORDER_PENDING,
            legacySentColumn: 'admin_pending_order_notified_at',
            chatId: $chatId,
        );
    }

    public function recordAdminOrderPaidNotification(Order $order, ?string $chatId = null): ?TelegramOutboundEffect
    {
        return $this->recordAdminOrderNotification(
            order: $order,
            effectType: self::TYPE_ADMIN_PAID,
            templateVersion: self::TEMPLATE_ADMIN_ORDER_PAID,
            legacySentColumn: 'admin_paid_order_notified_at',
            chatId: $chatId,
        );
    }

    public function recordAdminOrderExpiredNotification(Order $order, ?string $chatId = null): ?TelegramOutboundEffect
    {
        return $this->recordAdminOrderNotification(
            order: $order,
            effectType: self::TYPE_ADMIN_EXPIRED,
            templateVersion: self::TEMPLATE_ADMIN_ORDER_EXPIRED,
            legacySentColumn: 'admin_expired_order_notified_at',
            chatId: $chatId,
        );
    }

    /**
     * Materialize one independent Admin Broadcast recipient delivery unit.
     *
     * The recipient snapshot remains the source of the immutable audience
     * decision. The shared ledger owns the Telegram side effect and its
     * ambiguity-safe claim/finalization protocol.
     */
    public function recordAdminBroadcastRecipient(
        TelegramBroadcast $broadcast,
        TelegramBroadcastRecipient $recipient,
        string|TelegramOutboundEffectState|null $state = null,
        ?CarbonInterface $sentAt = null,
        ?CarbonInterface $failedAt = null,
        ?CarbonInterface $unknownAt = null,
        ?CarbonInterface $nextAttemptAt = null,
        ?string $lastErrorCode = null,
        ?string $lastError = null,
    ): TelegramOutboundEffect {
        $effectKey = self::adminBroadcastRecipientEffectKey(
            (string) $broadcast->broadcast_key,
            (string) $recipient->getKey(),
        );
        $state ??= TelegramOutboundEffectState::Pending->value;
        $state = $state instanceof TelegramOutboundEffectState ? $state->value : $state;

        $now = now();
        TelegramOutboundEffect::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'effect_key' => $effectKey,
            'effect_group_key' => $effectKey,
            'sequence' => 1,
            'effect_type' => self::TYPE_ADMIN_BROADCAST,
            'source_type' => TelegramBroadcastRecipient::class,
            'source_id' => (string) $recipient->getKey(),
            'target_type' => 'customer',
            'telegram_chat_id' => (string) $recipient->telegram_id,
            'queue' => 'telegram-broadcast',
            'telegram_method' => filled($broadcast->photo_file_id) ? 'sendPhoto' : 'sendMessage',
            'locale' => $recipient->locale,
            'template_version' => self::TEMPLATE_ADMIN_BROADCAST,
            'state' => $state,
            'attempts' => (int) $recipient->attempts,
            'next_attempt_at' => $nextAttemptAt ?? ($state === TelegramOutboundEffectState::Pending->value ? $now : null),
            'sent_at' => $sentAt,
            'failed_at' => $failedAt,
            'unknown_at' => $unknownAt,
            'last_error_code' => $lastErrorCode,
            'last_error' => $lastError,
            'outcome_metadata' => json_encode([
                'delivery_context' => 'admin_broadcast',
                'broadcast_source' => (string) ($broadcast->source ?: 'admin'),
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TelegramOutboundEffect::query()->where('effect_key', $effectKey)->firstOrFail();
    }

    /**
     * Materialize one stock movement delivery unit. The recipient row is the
     * immutable rendered snapshot; the shared effect owns delivery state.
     */
    public function recordStockNotificationRecipient(
        StockNotificationRecipient $recipient,
        int|string $movementId,
        string|TelegramOutboundEffectState|null $state = null,
        ?CarbonInterface $sentAt = null,
        ?CarbonInterface $failedAt = null,
        ?CarbonInterface $unknownAt = null,
        ?CarbonInterface $nextAttemptAt = null,
        ?string $lastErrorCode = null,
        ?string $lastError = null,
    ): TelegramOutboundEffect {
        $effectKey = self::stockNotificationEffectKey((string) $movementId, (string) $recipient->getKey());
        $state = $state instanceof TelegramOutboundEffectState
            ? $state->value
            : ($state ?? TelegramOutboundEffectState::Pending->value);
        $now = now();

        TelegramOutboundEffect::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'effect_key' => $effectKey,
            'effect_group_key' => 'inventory:'.$movementId.':'.self::TYPE_STOCK_NOTIFICATION.':v1',
            'sequence' => 1,
            'effect_type' => self::TYPE_STOCK_NOTIFICATION,
            'source_type' => StockNotificationRecipient::class,
            'source_id' => (string) $recipient->getKey(),
            'target_type' => trim((string) $recipient->recipient_type) ?: 'customer',
            'telegram_chat_id' => (string) $recipient->telegram_id,
            'queue' => 'telegram-broadcast',
            'telegram_method' => 'sendMessage',
            'locale' => $recipient->locale,
            'template_version' => self::TEMPLATE_STOCK_NOTIFICATION,
            'state' => $state,
            'attempts' => (int) $recipient->attempts,
            'next_attempt_at' => $nextAttemptAt ?? ($state === TelegramOutboundEffectState::Pending->value ? $now : null),
            'sent_at' => $sentAt,
            'failed_at' => $failedAt,
            'unknown_at' => $unknownAt,
            'last_error_code' => $lastErrorCode,
            'last_error' => $lastError,
            'outcome_metadata' => json_encode([
                'delivery_context' => 'stock_notification',
                'recipient_type' => trim((string) $recipient->recipient_type) ?: 'customer',
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TelegramOutboundEffect::query()->where('effect_key', $effectKey)->firstOrFail();
    }

    /**
     * Materialize one localized product-price recipient delivery unit.
     */
    public function recordPriceChangeNotificationRecipient(
        ProductPriceChangeNotificationRecipient $recipient,
        int|string $eventId,
        string|TelegramOutboundEffectState|null $state = null,
        ?CarbonInterface $sentAt = null,
        ?CarbonInterface $failedAt = null,
        ?CarbonInterface $unknownAt = null,
        ?CarbonInterface $nextAttemptAt = null,
        ?string $lastErrorCode = null,
        ?string $lastError = null,
    ): TelegramOutboundEffect {
        $effectKey = self::priceChangeRecipientEffectKey((string) $eventId, (string) $recipient->getKey());
        $state = $state instanceof TelegramOutboundEffectState
            ? $state->value
            : ($state ?? TelegramOutboundEffectState::Pending->value);
        $now = now();

        TelegramOutboundEffect::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'effect_key' => $effectKey,
            'effect_group_key' => 'price-change:'.$eventId.':v1',
            'sequence' => 1,
            'effect_type' => self::TYPE_PRICE_CHANGE_NOTIFICATION,
            'source_type' => ProductPriceChangeNotificationRecipient::class,
            'source_id' => (string) $recipient->getKey(),
            'target_type' => 'customer',
            'telegram_chat_id' => (string) $recipient->telegram_id,
            'queue' => 'telegram-broadcast',
            'telegram_method' => 'sendMessage',
            'locale' => $recipient->locale,
            'template_version' => self::TEMPLATE_PRICE_CHANGE_NOTIFICATION,
            'state' => $state,
            'attempts' => (int) $recipient->attempts,
            'next_attempt_at' => $nextAttemptAt ?? ($state === TelegramOutboundEffectState::Pending->value ? $now : null),
            'sent_at' => $sentAt,
            'failed_at' => $failedAt,
            'unknown_at' => $unknownAt,
            'last_error_code' => $lastErrorCode,
            'last_error' => $lastError,
            'outcome_metadata' => json_encode([
                'delivery_context' => 'price_change_notification',
                'recipient_type' => 'customer',
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TelegramOutboundEffect::query()->where('effect_key', $effectKey)->firstOrFail();
    }

    /**
     * Materialize the single Store Channel unit for a price-change event.
     */
    public function recordPriceChangeStoreChannel(
        ProductPriceChangeEvent $event,
        string $chatId,
        string|TelegramOutboundEffectState|null $state = null,
        ?CarbonInterface $sentAt = null,
        ?CarbonInterface $failedAt = null,
        ?CarbonInterface $unknownAt = null,
        ?CarbonInterface $nextAttemptAt = null,
        ?string $lastErrorCode = null,
        ?string $lastError = null,
    ): TelegramOutboundEffect {
        $effectKey = self::priceChangeStoreChannelEffectKey((string) $event->getKey());
        $state = $state instanceof TelegramOutboundEffectState
            ? $state->value
            : ($state ?? TelegramOutboundEffectState::Pending->value);
        $now = now();

        TelegramOutboundEffect::query()->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'effect_key' => $effectKey,
            'effect_group_key' => 'price-change:'.$event->getKey().':v1',
            'sequence' => 1,
            'effect_type' => self::TYPE_PRICE_CHANGE_STORE_CHANNEL,
            'source_type' => ProductPriceChangeEvent::class,
            'source_id' => (string) $event->getKey(),
            'target_type' => 'store_channel',
            'telegram_chat_id' => $chatId,
            'queue' => 'telegram-broadcast',
            'telegram_method' => 'sendMessage',
            'locale' => TelegramLocale::STORE_CHANNEL_LOCALE,
            'template_version' => self::TEMPLATE_PRICE_CHANGE_STORE_CHANNEL,
            'state' => $state,
            'attempts' => (int) $event->store_channel_attempts,
            'next_attempt_at' => $nextAttemptAt ?? ($state === TelegramOutboundEffectState::Pending->value ? $now : null),
            'sent_at' => $sentAt,
            'failed_at' => $failedAt,
            'unknown_at' => $unknownAt,
            'last_error_code' => $lastErrorCode,
            'last_error' => $lastError,
            'outcome_metadata' => json_encode([
                'delivery_context' => 'price_change_store_channel',
                'recipient_type' => 'store_channel',
            ], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return TelegramOutboundEffect::query()->where('effect_key', $effectKey)->firstOrFail();
    }

    public function recordManualPaymentProof(Order $order, Payment $payment, ?string $chatId = null): ?TelegramOutboundEffect
    {
        $chatId = $this->adminTargetChatId($chatId);
        if ($chatId === null || blank(config('services.telegram.token'))) {
            return null;
        }

        $effectKey = self::manualPaymentProofEffectKey((string) $payment->getKey());
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($payment->payment_proof_forwarded_at !== null) {
            return null;
        }

        if ($payment->payment_proof_file_id === null || $payment->payment_proof_submitted_at === null) {
            return null;
        }

        $lateEvidence = $payment->method === 'BINANCE_PAY_ID'
            && in_array($payment->status, [PaymentStatus::Expired, PaymentStatus::Failed], true)
            && in_array($order->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => self::TYPE_MANUAL_PAYMENT_PROOF,
                'source_type' => Payment::class,
                'source_id' => (string) $payment->getKey(),
                'target_type' => 'admin',
                'telegram_chat_id' => $chatId,
                'queue' => 'notifications',
                'telegram_method' => 'sendPhoto',
                'locale' => $order->customer?->locale,
                'template_version' => self::TEMPLATE_MANUAL_PAYMENT_PROOF,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'outcome_metadata' => [
                    'delivery_context' => 'manual_payment_proof',
                    'attachment_type' => 'telegram_file_reference',
                    'attachment_present' => true,
                    'payment_method' => (string) $payment->method,
                    'late_evidence' => $lateEvidence,
                    'proof_status' => $payment->status instanceof PaymentStatus
                        ? $payment->status->value
                        : (string) $payment->getRawOriginal('status'),
                ],
            ],
        );
    }

    /**
     * Materialize the Sales Proof delivery unit in the same transaction as
     * the Paid Order Proof Event. SalesProofPost remains a projection of the
     * ledger outcome; it is not a second send claim.
     */
    public function recordSalesProofPost(Order $order, ?SalesProofPost $post = null): ?TelegramOutboundEffect
    {
        $post ??= SalesProofPost::query()->where('order_id', $order->getKey())->first();
        if ($post === null) {
            return null;
        }

        $setting = StoreChannelSetting::current();
        if (! $setting->sales_proof_enabled) {
            return null;
        }

        $chatId = trim((string) ($setting->telegram_chat_id ?: $setting->channel_username));
        if ($chatId === '') {
            $this->markSalesProofUnpublishable($post, 'Store Channel chat is not configured.');

            return null;
        }

        $effectKey = self::salesProofEffectKey((string) $order->getKey());
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            $this->synchronizeSalesProofProjection($existing, $post);

            return $existing;
        }

        $projectionState = $this->salesProofProjectionState($post);
        $now = now();

        $effect = TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => self::TYPE_SALES_PROOF,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'store_channel',
                'telegram_chat_id' => $chatId,
                'queue' => self::SALES_PROOF_QUEUE,
                'telegram_method' => 'sendPhoto',
                'locale' => 'en',
                'template_version' => self::TEMPLATE_SALES_PROOF,
                'state' => $projectionState,
                'attempts' => (int) $post->attempts,
                'next_attempt_at' => in_array($projectionState, [
                    TelegramOutboundEffectState::Pending->value,
                    TelegramOutboundEffectState::Failed->value,
                ], true) ? $now : null,
                'telegram_message_id' => $post->telegram_message_id,
                'sent_at' => $post->sent_at,
                'unknown_at' => in_array($projectionState, self::REVIEW_STATES, true) ? ($post->updated_at ?? $now) : null,
                'manual_review_at' => $projectionState === TelegramOutboundEffectState::ManualReview->value ? ($post->updated_at ?? $now) : null,
                'last_error_code' => match ($projectionState) {
                    TelegramOutboundEffectState::Unknown->value, TelegramOutboundEffectState::ManualReview->value => 'legacy_sending_ambiguous',
                    TelegramOutboundEffectState::Failed->value => 'legacy_sales_proof_failure',
                    default => null,
                },
                'last_error' => match ($projectionState) {
                    TelegramOutboundEffectState::Unknown->value, TelegramOutboundEffectState::ManualReview->value => 'Sales Proof delivery outcome is ambiguous and requires review.',
                    TelegramOutboundEffectState::Failed->value => $post->last_error ?: 'Sales Proof delivery failed before completion.',
                    default => null,
                },
                'outcome_metadata' => [
                    'delivery_context' => 'sales_proof',
                    'unit_role' => 'invoice',
                    'reason' => in_array($projectionState, self::REVIEW_STATES, true)
                        ? 'legacy_sending_ambiguous'
                        : 'sales_proof',
                ],
            ],
        );

        if ($projectionState === TelegramOutboundEffectState::Unknown->value) {
            $this->markSalesProofUnknown(
                $effect,
                $post->updated_at ?? $now,
                'Legacy Sales Proof delivery outcome is ambiguous and requires review.',
            );
        } elseif ($projectionState === TelegramOutboundEffectState::ManualReview->value) {
            $this->markSalesProofManualReview($effect, $post->updated_at ?? $now);
        }

        return $effect;
    }

    /**
     * Resolve a Sales Proof job's source row and effect. This compatibility
     * seam lets pre-ledger jobs finish safely while new callers always pass
     * the effect identifier explicitly.
     */
    public function materializeSalesProofPostEffect(Order $order, bool $manualRetry = false): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($order, $manualRetry): ?TelegramOutboundEffect {
            $lockedOrder = Order::query()->lockForUpdate()->find($order->getKey());
            if ($lockedOrder === null) {
                return null;
            }

            $post = SalesProofPost::query()
                ->where('order_id', $lockedOrder->getKey())
                ->lockForUpdate()
                ->first();
            if ($post === null) {
                return null;
            }

            $effect = TelegramOutboundEffect::query()
                ->where('effect_key', self::salesProofEffectKey((string) $lockedOrder->getKey()))
                ->lockForUpdate()
                ->first();
            $effect ??= $this->recordSalesProofPost($lockedOrder, $post);

            if ($effect !== null) {
                $this->synchronizeSalesProofProjection($effect, $post);
            }

            if ($effect !== null && $manualRetry && $this->stateValue($effect) === TelegramOutboundEffectState::Failed->value) {
                $effect = $this->resetSalesProofForRetry($effect);
            }

            return $effect?->fresh();
        }, 5);
    }

    /**
     * Reset only a definite Sales Proof failure. Unknown and manual-review
     * effects must go through the explicit duplicate-risk resend flow.
     */
    public function resetSalesProofForRetry(TelegramOutboundEffect $effect): TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effect): TelegramOutboundEffect {
            $reset = $this->ledger->requestManualRetry((string) $effect->getKey());
            if ($reset === null) {
                throw new InvalidArgumentException('Only a failed Sales Proof effect can be retried.');
            }

            SalesProofPost::query()
                ->where('order_id', $reset->source_id)
                ->where('status', SalesProofPost::STATUS_FAILED)
                ->update([
                    'status' => SalesProofPost::STATUS_PENDING,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return $reset->fresh();
        }, 5);
    }

    public static function salesProofEffectKey(string $orderId): string
    {
        return 'order:'.$orderId.':'.self::TYPE_SALES_PROOF.':v1';
    }

    /** Compatibility alias for callers that name the projection explicitly. */
    public static function salesProofPostEffectKey(string $orderId): string
    {
        return self::salesProofEffectKey($orderId);
    }

    public function hasConfiguredAdminTarget(): bool
    {
        return $this->adminTargetChatId() !== null && filled(config('services.telegram.token'));
    }

    /**
     * Materialize the paid customer receipt as an ordered effect group.
     *
     * The caller may already be inside the paid-lifecycle transaction. The
     * nested transaction keeps direct legacy callers atomic as well, while
     * Laravel's afterCommit dispatch remains owned by the caller.
     */
    public function recordReceipt(Order $order, ?Customer $customer = null): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($order, $customer): ?TelegramOutboundEffect {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $customer ??= $locked->customer;

            if (! $this->receiptEligible($locked, $customer)) {
                return null;
            }

            $groupKeys = [
                self::receiptTextFallbackEffectGroupKey((string) $locked->getKey()),
                self::receiptEffectGroupKey((string) $locked->getKey()),
                self::legacyReceiptEffectGroupKey((string) $locked->getKey()),
            ];
            $existingGroups = TelegramOutboundEffect::query()
                ->whereIn('effect_group_key', $groupKeys)
                ->orderBy('sequence')
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('effect_group_key');

            foreach ($groupKeys as $groupKey) {
                if (! $existingGroups->has($groupKey)) {
                    continue;
                }

                $ready = $this->ledger->nextReceiptEffect($groupKey);
                if ($ready !== null) {
                    return $ready;
                }
            }

            if ($existingGroups->isNotEmpty()) {
                return null;
            }

            if ($locked->telegram_receipt_sent_at !== null) {
                return null;
            }

            return $this->createReceiptEffectGroup($locked, $customer);
        }, 5);
    }

    /**
     * Materialize the independent action panel after a newly opted-in receipt
     * group has completed. The receipt group and its completion projection are
     * the only source of the safe panel summary; credentials are never loaded.
     */
    public function recordReceiptActionPanel(TelegramOutboundEffect $receiptEffect): ?TelegramOutboundEffect
    {
        $created = false;
        $panel = DB::transaction(function () use ($receiptEffect, &$created): ?TelegramOutboundEffect {
            // Lock the source Order before the receipt rows. Recovery and
            // delivery paths can arrive with different receipt-unit IDs; a
            // stable lock order prevents those paths from deadlocking while
            // they inspect the same completed group.
            $receiptReference = TelegramOutboundEffect::query()->find($receiptEffect->getKey());
            if ($receiptReference === null || ! $this->receiptActionPanelSource($receiptReference)) {
                return null;
            }

            $groupKey = (string) $receiptReference->effect_group_key;
            $order = Order::query()
                ->with(['customer', 'payments', 'orderItems'])
                ->lockForUpdate()
                ->find($receiptReference->source_id);
            if ($order === null || $order->telegram_receipt_sent_at === null) {
                return null;
            }

            $lockedReceipt = TelegramOutboundEffect::query()
                ->lockForUpdate()
                ->find($receiptReference->getKey());
            if ($lockedReceipt === null || ! $this->receiptActionPanelSource($lockedReceipt)) {
                return null;
            }

            $customer = $order->customer;
            if (! $this->receiptActionPanelEligible($order, $customer)) {
                return null;
            }

            $receiptEffects = TelegramOutboundEffect::query()
                ->where('effect_type', self::TYPE_RECEIPT_CREDENTIALS)
                ->where('effect_group_key', $groupKey)
                ->orderBy('sequence')
                ->lockForUpdate()
                ->get();
            $firstReceipt = $receiptEffects->first();
            if ($firstReceipt === null
                || $firstReceipt->sequence !== 1
                || $firstReceipt->telegram_method !== 'sendPhoto'
                || $firstReceipt->template_version !== self::TEMPLATE_RECEIPT_PHOTO_CAPTION) {
                return null;
            }

            if (! $this->ledger->isReceiptGroupComplete($groupKey)) {
                return null;
            }

            $effectKey = self::receiptActionPanelEffectKey((string) $order->getKey(), $groupKey);
            $existing = TelegramOutboundEffect::query()
                ->where('effect_key', $effectKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->effect_type !== self::TYPE_RECEIPT_ORDER_ACTIONS
                    || $existing->source_type !== Order::class
                    || (string) $existing->source_id !== (string) $order->getKey()
                    || $existing->target_type !== 'customer'
                    || (string) $existing->telegram_chat_id !== (string) $customer?->telegram_id) {
                    return null;
                }

                return $existing;
            }

            $receiptMetadata = is_array($firstReceipt->outcome_metadata)
                ? $firstReceipt->outcome_metadata
                : [];
            if (($receiptMetadata['receipt_actions_version'] ?? null) !== self::RECEIPT_ACTIONS_OPT_IN_VERSION) {
                return null;
            }

            $accountCount = $this->optionalNonNegativeCount($receiptMetadata['account_count'] ?? null);
            if ($accountCount === null) {
                return null;
            }

            try {
                [$fallbackProductCount, $itemCount] = $this->orderSummaryCounts($order);
                $productCount = $this->optionalNonNegativeCount($receiptMetadata['product_count'] ?? null)
                    ?? $fallbackProductCount;
                $txtAvailable = $accountCount > 0;
                $panel = $this->receiptActionPanel->render(
                    $order,
                    (string) ($customer?->locale ?? TelegramLocale::DEFAULT_LOCALE),
                    $txtAvailable,
                );
                $keyboard = $panel->replyMarkup();
                $keyboardBytes = strlen(json_encode($keyboard, JSON_THROW_ON_ERROR));
            } catch (Throwable) {
                return null;
            }

            $now = now();

            $created = true;

            return TelegramOutboundEffect::query()->create([
                'effect_key' => $effectKey,
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => self::TYPE_RECEIPT_ORDER_ACTIONS,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => self::RECEIPT_QUEUE,
                'telegram_method' => 'sendMessage',
                'locale' => $customer->locale,
                'template_version' => self::TEMPLATE_RECEIPT_ORDER_ACTIONS,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'outcome_metadata' => [
                    'delivery_context' => 'receipt_actions',
                    'action_count' => count($keyboard['inline_keyboard'] ?? []),
                    'txt_available' => $txtAvailable,
                    'product_count' => $productCount,
                    'item_count' => $itemCount,
                    'receipt_group_key' => $groupKey,
                    'receipt_actions_version' => self::RECEIPT_ACTIONS_OPT_IN_VERSION,
                    'copy_length_bucket' => $this->receiptLengthBucket($panel->characterLength()),
                    'keyboard_length_bucket' => $this->receiptLengthBucket($keyboardBytes),
                ],
            ]);
        }, 5);

        if ($created && $panel !== null) {
            $this->observability->actionPanelMaterialized($panel);
        }

        return $panel;
    }

    /**
     * Materialize one explicitly requested, independently delivered TXT file.
     *
     * The request nonce is opaque and becomes the durable idempotency
     * component. Credential values are intentionally not loaded here.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function recordReceiptTxtDownload(
        Order $order,
        string $requestNonce,
        ?Customer $customer = null,
        array $metadata = [],
    ): ?TelegramOutboundEffect {
        $requestNonce = self::normalizeTxtDownloadNonce($requestNonce);
        $requestedMetadata = $this->safeReceiptTxtDownloadMetadata($metadata, $requestNonce);

        return DB::transaction(function () use ($order, $requestNonce, $customer, $requestedMetadata): ?TelegramOutboundEffect {
            $locked = Order::query()
                ->with(['customer', 'payments', 'orderItems'])
                ->lockForUpdate()
                ->findOrFail($order->getKey());
            $owner = $locked->customer;

            if ($customer !== null && (string) $customer->getKey() !== (string) $locked->customer_id) {
                return null;
            }

            if (! $this->receiptTxtDownloadEligible($locked, $owner)) {
                return null;
            }

            $effectKey = self::receiptTxtDownloadEffectKey((string) $locked->getKey(), $requestNonce);
            $existing = TelegramOutboundEffect::query()
                ->where('effect_key', $effectKey)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                if ($existing->effect_type !== self::TYPE_RECEIPT_TXT_DOWNLOAD
                    || $existing->source_type !== Order::class
                    || (string) $existing->source_id !== (string) $locked->getKey()
                    || $existing->target_type !== 'customer'
                    || (string) $existing->telegram_chat_id !== (string) $owner?->telegram_id) {
                    return null;
                }

                return $existing;
            }

            [$productCount, $itemCount] = $this->orderSummaryCounts($locked);
            $safeMetadata = [
                ...$requestedMetadata,
                'product_count' => $productCount,
                'item_count' => $itemCount,
            ];
            $now = now();

            return TelegramOutboundEffect::query()->create([
                'effect_key' => $effectKey,
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => self::TYPE_RECEIPT_TXT_DOWNLOAD,
                'source_type' => Order::class,
                'source_id' => (string) $locked->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $owner->telegram_id,
                'queue' => self::RECEIPT_QUEUE,
                'telegram_method' => 'sendDocument',
                'locale' => $owner->locale,
                'template_version' => self::TEMPLATE_RECEIPT_TXT_DOWNLOAD,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => $now,
                'outcome_metadata' => $safeMetadata,
            ]);
        }, 5);
    }

    /**
     * Materialize a safe text-only replacement after a v2 photo failed before
     * Telegram was called. The failed photo effect remains immutable history.
     */
    public function recordReceiptTextFallback(TelegramOutboundEffect $failed): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($failed): ?TelegramOutboundEffect {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($failed->getKey());
            if ($locked->state !== TelegramOutboundEffectState::Failed
                || $locked->template_version !== self::TEMPLATE_RECEIPT_PHOTO_CAPTION
                || $locked->sequence !== 1
                || $locked->last_error_code !== 'receipt_render_changed') {
                return null;
            }

            $order = Order::query()->lockForUpdate()->findOrFail($locked->source_id);
            $customer = $order->customer;
            if (! $this->receiptEligible($order, $customer)) {
                return null;
            }

            $groupKey = self::receiptTextFallbackEffectGroupKey((string) $order->getKey());
            if (TelegramOutboundEffect::query()->where('effect_group_key', $groupKey)->exists()) {
                return $this->ledger->nextReceiptEffect($groupKey);
            }

            try {
                $plan = $this->receiptNotifier->receiptPlan($order);
            } catch (\Throwable) {
                return null;
            }

            return $this->createReceiptTransportEffectGroup(
                $order,
                $customer,
                $groupKey,
                $plan,
                textFallback: true,
                explicit: false,
                supersedes: $locked,
            );
        }, 5);
    }

    public static function receiptTextFallbackEffectGroupKey(string $orderId): string
    {
        return self::receiptEffectGroupKey($orderId).':text-fallback';
    }

    /**
     * Create an explicit, duplicate-risk-acknowledged receipt resend group.
     * This is intentionally separate from the deterministic first-delivery
     * group so the original effect history remains immutable.
     */
    public function recordReceiptResend(Order $order): TelegramOutboundEffect
    {
        return DB::transaction(function () use ($order): TelegramOutboundEffect {
            $locked = Order::query()->lockForUpdate()->findOrFail($order->getKey());
            $customer = $locked->customer;

            if (! $this->receiptEligible($locked, $customer)) {
                throw new InvalidArgumentException('Receipt can only be delivered for a paid order with a Telegram customer.');
            }

            return $this->createReceiptEffectGroup($locked, $customer, explicit: true, supersedes: $this->latestReceiptEffect($locked));
        }, 5);
    }

    public static function receiptEffectGroupKey(string $orderId): string
    {
        return 'order:'.$orderId.':'.self::TYPE_RECEIPT_CREDENTIALS.':v2';
    }

    public static function legacyReceiptEffectGroupKey(string $orderId): string
    {
        return 'order:'.$orderId.':'.self::TYPE_RECEIPT_CREDENTIALS.':v1';
    }

    public static function receiptPhotoCaptionEffectGroupKey(string $orderId): string
    {
        return self::receiptEffectGroupKey($orderId);
    }

    public static function receiptEffectKey(string $orderId, int $sequence): string
    {
        return self::receiptEffectGroupKey($orderId).':unit:'.$sequence;
    }

    public static function receiptTxtDownloadEffectKey(string $orderId, string $requestNonce): string
    {
        $orderId = trim($orderId);
        if ($orderId === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $orderId) !== 1) {
            throw new InvalidArgumentException('TXT download Order reference is invalid.');
        }

        return 'order:'.$orderId.':telegram_txt_download:'.self::normalizeTxtDownloadNonce($requestNonce).':v1';
    }

    public static function receiptActionPanelEffectKey(string $orderId, string $receiptGroupKey): string
    {
        $orderId = trim($orderId);
        if ($orderId === '' || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/D', $orderId) !== 1) {
            throw new InvalidArgumentException('Receipt action panel Order reference is invalid.');
        }

        $baseGroupKey = self::receiptEffectGroupKey($orderId);
        if ($receiptGroupKey === $baseGroupKey) {
            $version = 'v2';
        } elseif (preg_match('/\A'.preg_quote($baseGroupKey, '/').':resend:v([1-9][0-9]*)\z/D', $receiptGroupKey, $matches) === 1) {
            $version = 'resend-v'.$matches[1];
        } else {
            throw new InvalidArgumentException('Receipt action panel receipt group reference is invalid.');
        }

        return 'order:'.$orderId.':telegram_receipt_actions:v1:'.$version;
    }

    /** Compatibility alias for callers that name the group explicitly. */
    public static function receiptActionPanelEffectGroupKey(string $orderId, string $receiptGroupKey): string
    {
        return self::receiptActionPanelEffectKey($orderId, $receiptGroupKey);
    }

    /** Compatibility alias for the effect-family name. */
    public static function receiptOrderActionsEffectKey(string $orderId, string $receiptGroupKey): string
    {
        return self::receiptActionPanelEffectKey($orderId, $receiptGroupKey);
    }

    /** Compatibility alias for the effect-family group name. */
    public static function receiptOrderActionsEffectGroupKey(string $orderId, string $receiptGroupKey): string
    {
        return self::receiptActionPanelEffectKey($orderId, $receiptGroupKey);
    }

    public static function typeForPayment(Payment $payment): ?string
    {
        return match (true) {
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'QR_MANUAL' => self::TYPE_MANUAL_CHECKOUT,
            $payment->provider === PaymentProvider::KLIKQRIS => self::TYPE_KLIKQRIS_CHECKOUT,
            $payment->provider === PaymentProvider::SAKURUPIAH => self::TYPE_SAKURUPIAH_CHECKOUT,
            $payment->provider === PaymentProvider::PAKASIR => self::TYPE_PAKASIR_CHECKOUT,
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'BINANCE_PAY_ID' => self::TYPE_BINANCE_CHECKOUT,
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'USDT_BEP20' => self::TYPE_BSC_CHECKOUT,
            default => null,
        };
    }

    public static function checkoutEffectKey(string $orderId, string $effectType): string
    {
        return 'order:'.$orderId.':'.$effectType.':v1';
    }

    public static function klikQrisPresentationRecoveryEffectKey(string $paymentId): string
    {
        return 'payment:'.$paymentId.':'.self::TYPE_KLIKQRIS_CHECKOUT.':presentation-recovery:v1';
    }

    public static function sakurupiahPresentationRecoveryEffectKey(string $paymentId): string
    {
        return 'payment:'.$paymentId.':'.self::TYPE_SAKURUPIAH_CHECKOUT.':presentation-recovery:v1';
    }

    public static function adminOrderEffectKey(string $orderId, string $effectType): string
    {
        return self::checkoutEffectKey($orderId, $effectType);
    }

    public static function adminBroadcastRecipientEffectKey(string $broadcastKey, string $recipientId): string
    {
        return 'broadcast:'.$broadcastKey.':recipient:'.$recipientId.':v1';
    }

    /** Compatibility alias for callers that use the shorter family name. */
    public static function broadcastRecipientEffectKey(string $broadcastKey, string $recipientId): string
    {
        return self::adminBroadcastRecipientEffectKey($broadcastKey, $recipientId);
    }

    public static function stockNotificationEffectKey(string $movementId, string $recipientId): string
    {
        return 'inventory:'.$movementId.':'.self::TYPE_STOCK_NOTIFICATION.':recipient:'.$recipientId.':v1';
    }

    /** Compatibility alias for callers that use the shorter stock name. */
    public static function stockRecipientEffectKey(string $movementId, string $recipientId): string
    {
        return self::stockNotificationEffectKey($movementId, $recipientId);
    }

    public static function priceChangeRecipientEffectKey(string $eventId, string $recipientId): string
    {
        return 'price-change:'.$eventId.':recipient:'.$recipientId.':v1';
    }

    /** Compatibility aliases for price notification callers. */
    public static function productPriceChangeRecipientEffectKey(string $eventId, string $recipientId): string
    {
        return self::priceChangeRecipientEffectKey($eventId, $recipientId);
    }

    public static function priceChangeStoreChannelEffectKey(string $eventId): string
    {
        return 'price-change:'.$eventId.':store-channel:v1';
    }

    public static function manualPaymentProofEffectKey(string $paymentId): string
    {
        return 'payment:'.$paymentId.':'.self::TYPE_MANUAL_PAYMENT_PROOF.':v1';
    }

    public static function manualCheckoutEffectKey(string $orderId): string
    {
        return self::checkoutEffectKey($orderId, self::TYPE_MANUAL_CHECKOUT);
    }

    public function forOrder(string $orderId, ?string $effectType = null): ?TelegramOutboundEffect
    {
        $query = TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', $orderId)
            ->when($effectType !== null, fn (Builder $query) => $query->where('effect_type', $effectType))
            ->when($effectType === null, fn (Builder $query) => $query->whereIn('effect_type', self::CHECKOUT_EFFECT_TYPES))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $query->first();
    }

    public function forPayment(string $paymentId, ?string $effectType = null): ?TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()
            ->where('source_type', Payment::class)
            ->where('source_id', $paymentId)
            ->when($effectType !== null, fn (Builder $query) => $query->where('effect_type', $effectType))
            ->when($effectType === null, fn (Builder $query) => $query->whereIn('effect_type', [self::TYPE_MANUAL_PAYMENT_PROOF]))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Builder<TelegramOutboundEffect> */
    public function reviewQuery(): Builder
    {
        return TelegramOutboundEffect::query()
            ->whereIn('state', self::REVIEW_STATES)
            ->orderByRaw('case when state = ? then 0 else 1 end', [TelegramOutboundEffectState::ManualReview->value])
            ->latest('updated_at');
    }

    /**
     * Build the only representation of an effect that an operator-facing view may receive.
     *
     * @return array<string, mixed>
     */
    public function reviewSummary(TelegramOutboundEffect $effect): array
    {
        $state = $this->stateValue($effect);
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return [
            // The review queue only needs a route key. Do not hand the raw
            // Eloquent row (including last_error, chat IDs, or unfiltered
            // metadata) to a view/serializer.
            'effect' => $this->reviewRouteEffect($effect),
            'state' => $state,
            'effect_type' => (string) $effect->effect_type,
            'source_reference' => $this->sourceReference($effect),
            'target' => $this->redactedTarget($effect),
            'queue' => (string) ($effect->queue ?: '—'),
            'telegram_method' => (string) ($effect->telegram_method ?: '—'),
            'locale' => (string) ($effect->locale ?: '—'),
            'template_version' => (string) ($effect->template_version ?: '—'),
            'sequence' => (int) $effect->sequence,
            'attempts' => (int) $effect->attempts,
            'error_code' => $this->safeErrorCode($effect->last_error_code),
            'metadata' => $this->safeReviewMetadata($metadata),
            'duplicate_risk_acknowledged' => ($metadata['duplicate_risk_acknowledged'] ?? false) === true
                || $effect->supersedes_effect_id !== null,
            'supersedes_effect_id' => $effect->supersedes_effect_id,
            'created_at' => $effect->created_at?->format('Y-m-d H:i:s') ?? '—',
            'updated_at' => $effect->updated_at?->format('Y-m-d H:i:s') ?? '—',
            'unknown_at' => $effect->unknown_at?->format('Y-m-d H:i:s') ?? '—',
            'manual_review_at' => $effect->manual_review_at?->format('Y-m-d H:i:s') ?? '—',
            'sent_at' => $effect->sent_at?->format('Y-m-d H:i:s') ?? '—',
            'can_resolve' => in_array($state, self::REVIEW_STATES, true),
        ];
    }

    private function reviewRouteEffect(TelegramOutboundEffect $effect): TelegramOutboundEffect
    {
        $routeEffect = new TelegramOutboundEffect;
        $routeEffect->setRawAttributes(['id' => (string) $effect->getKey()], true);
        $routeEffect->exists = true;

        return $routeEffect;
    }

    public function resend(TelegramOutboundEffect $effect, User $actor, ?string $ip = null): TelegramOutboundEffect
    {
        if ($effect->effect_type === self::TYPE_RECEIPT_CREDENTIALS) {
            return $this->resendReceiptEffect($effect, $actor, $ip);
        }

        if ($effect->effect_type === self::TYPE_RECEIPT_ORDER_ACTIONS) {
            return $this->resendReceiptActionPanel($effect, $actor, $ip);
        }

        return DB::transaction(function () use ($effect, $actor, $ip): TelegramOutboundEffect {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effect->getKey());
            if (! $this->isReviewable($locked)) {
                throw new InvalidArgumentException('Only unknown or manual-review effects can be resent.');
            }

            $groupKey = (string) ($locked->effect_group_key ?: $locked->effect_key);
            $latest = TelegramOutboundEffect::query()
                ->where('effect_group_key', $groupKey)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $sequence = max((int) $locked->sequence, (int) ($latest?->sequence ?? 0)) + 1;
            $effectKey = $groupKey.':resend:v'.$sequence;

            while (TelegramOutboundEffect::query()->where('effect_key', $effectKey)->exists()) {
                $sequence++;
                $effectKey = $groupKey.':resend:v'.$sequence;
            }

            $resend = TelegramOutboundEffect::query()->create([
                'effect_key' => $effectKey,
                'effect_group_key' => $groupKey,
                'supersedes_effect_id' => $locked->getKey(),
                'sequence' => $sequence,
                'effect_type' => $locked->effect_type,
                'source_type' => $locked->source_type,
                'source_id' => $locked->source_id,
                'target_type' => $locked->target_type,
                'telegram_chat_id' => $locked->telegram_chat_id,
                'queue' => $locked->queue,
                'telegram_method' => $locked->telegram_method,
                'locale' => $locked->locale,
                'template_version' => $locked->template_version,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'outcome_metadata' => [
                    'delivery_context' => 'explicit_resend',
                    'duplicate_risk_acknowledged' => true,
                    'supersedes_effect_id' => (string) $locked->getKey(),
                ],
            ]);

            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.resent',
                $locked,
                ['state' => $this->stateValue($locked)],
                [
                    'new_effect_id' => (string) $resend->getKey(),
                    'sequence' => $sequence,
                    'duplicate_risk_acknowledged' => true,
                ],
                $actor,
                $ip,
            );

            return $resend;
        });
    }

    private function resendReceiptActionPanel(TelegramOutboundEffect $effect, User $actor, ?string $ip = null): TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effect, $actor, $ip): TelegramOutboundEffect {
            $reference = TelegramOutboundEffect::query()->findOrFail($effect->getKey());
            $lockedOrder = Order::query()
                ->with(['customer', 'payments'])
                ->lockForUpdate()
                ->findOrFail($reference->source_id);
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($reference->getKey());
            if (! $this->isReviewable($locked)) {
                throw new InvalidArgumentException('Only unknown or manual-review effects can be resent.');
            }

            $customer = $lockedOrder->customer;
            if (! $this->receiptActionPanelEligible($lockedOrder, $customer)
                || $lockedOrder->telegram_receipt_sent_at === null) {
                throw new InvalidArgumentException('Receipt action panel can only be delivered for an eligible paid Order.');
            }

            $metadata = is_array($locked->outcome_metadata) ? $locked->outcome_metadata : [];
            $receiptGroupKey = $metadata['receipt_group_key'] ?? null;
            if (! is_string($receiptGroupKey)
                || $receiptGroupKey === ''
                || self::receiptActionPanelEffectKey((string) $lockedOrder->getKey(), $receiptGroupKey) !== (string) $locked->effect_group_key) {
                throw new InvalidArgumentException('Receipt action panel source group is invalid.');
            }

            $latest = TelegramOutboundEffect::query()
                ->where('effect_group_key', $locked->effect_group_key)
                ->orderByDesc('sequence')
                ->lockForUpdate()
                ->first();
            $sequence = max((int) $locked->sequence, (int) ($latest?->sequence ?? 0)) + 1;
            $effectKey = (string) $locked->effect_group_key.':resend:v'.$sequence;

            while (TelegramOutboundEffect::query()->where('effect_key', $effectKey)->exists()) {
                $sequence++;
                $effectKey = (string) $locked->effect_group_key.':resend:v'.$sequence;
            }

            $resend = TelegramOutboundEffect::query()->create([
                'effect_key' => $effectKey,
                'effect_group_key' => $locked->effect_group_key,
                'supersedes_effect_id' => $locked->getKey(),
                'sequence' => $sequence,
                'effect_type' => self::TYPE_RECEIPT_ORDER_ACTIONS,
                'source_type' => Order::class,
                'source_id' => (string) $lockedOrder->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => self::RECEIPT_QUEUE,
                'telegram_method' => 'sendMessage',
                'locale' => $locked->locale ?: $customer->locale,
                'template_version' => self::TEMPLATE_RECEIPT_ORDER_ACTIONS,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'outcome_metadata' => [
                    'delivery_context' => 'explicit_resend',
                    'duplicate_risk_acknowledged' => true,
                    'supersedes_effect_id' => (string) $locked->getKey(),
                    'action_count' => $this->optionalNonNegativeCount($metadata['action_count'] ?? null) ?? 0,
                    'txt_available' => ($metadata['txt_available'] ?? false) === true,
                    'product_count' => $this->optionalNonNegativeCount($metadata['product_count'] ?? null) ?? 0,
                    'item_count' => $this->optionalNonNegativeCount($metadata['item_count'] ?? null) ?? 0,
                    'receipt_group_key' => $receiptGroupKey,
                    'receipt_actions_version' => self::RECEIPT_ACTIONS_OPT_IN_VERSION,
                    ...$this->safePanelSizeMetadata($metadata),
                ],
            ]);

            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.resent',
                $locked,
                ['state' => $this->stateValue($locked)],
                [
                    'new_effect_id' => (string) $resend->getKey(),
                    'sequence' => $sequence,
                    'duplicate_risk_acknowledged' => true,
                ],
                $actor,
                $ip,
            );

            return $resend;
        });
    }

    private function resendReceiptEffect(TelegramOutboundEffect $effect, User $actor, ?string $ip = null): TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effect, $actor, $ip): TelegramOutboundEffect {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effect->getKey());
            if (! $this->isReviewable($locked)) {
                throw new InvalidArgumentException('Only unknown or manual-review effects can be resent.');
            }

            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($locked->source_id);
            $customer = $lockedOrder->customer;
            if (! $this->receiptEligible($lockedOrder, $customer) || $customer === null) {
                throw new InvalidArgumentException('Receipt can only be delivered for a paid order with a Telegram customer.');
            }

            $resend = $this->createReceiptEffectGroup(
                $lockedOrder,
                $customer,
                explicit: true,
                supersedes: $locked,
            );

            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.resent',
                $locked,
                ['state' => $this->stateValue($locked)],
                [
                    'new_effect_id' => (string) $resend->getKey(),
                    'sequence' => (int) $resend->sequence,
                    'duplicate_risk_acknowledged' => true,
                ],
                $actor,
                $ip,
            );

            return $resend;
        }, 5);
    }

    private function createReceiptEffectGroup(
        Order $order,
        Customer $customer,
        bool $explicit = false,
        ?TelegramOutboundEffect $supersedes = null,
    ): TelegramOutboundEffect {
        $baseGroupKey = self::receiptEffectGroupKey((string) $order->getKey());
        $groupKey = $baseGroupKey;

        if ($explicit) {
            $version = 2;
            do {
                $groupKey = $baseGroupKey.':resend:v'.$version;
                $version++;
            } while (TelegramOutboundEffect::query()->where('effect_group_key', $groupKey)->exists());
        }

        try {
            $plan = $this->receiptNotifier->receiptPlan($order);
        } catch (\Throwable) {
            return $this->createReceiptRenderFailureEffect($order, $customer, $groupKey, $explicit, $supersedes);
        }

        $textFallback = false;
        try {
            $this->receiptNotifier->renderInvoiceUnit($order);
        } catch (\Throwable) {
            $textFallback = true;
        }

        return $this->createReceiptTransportEffectGroup(
            $order,
            $customer,
            $groupKey,
            $plan,
            textFallback: $textFallback,
            explicit: $explicit,
            supersedes: $supersedes,
        );
    }

    private function createReceiptTransportEffectGroup(
        Order $order,
        Customer $customer,
        string $groupKey,
        TelegramReceiptPlan $plan,
        bool $textFallback,
        bool $explicit,
        ?TelegramOutboundEffect $supersedes,
    ): TelegramOutboundEffect {
        $unitCount = $textFallback ? $plan->textUnitCount() : $plan->photoUnitCount();
        $template = $textFallback
            ? self::TEMPLATE_RECEIPT_TEXT_FALLBACK
            : self::TEMPLATE_RECEIPT_PHOTO_CAPTION;
        $planMetadata = $this->receiptPlanMetadata($plan, $textFallback);
        $planMetadata['render_mode'] = $textFallback ? 'text_fallback' : 'photo_caption';

        $first = null;
        for ($sequence = 1; $sequence <= $unitCount; $sequence++) {
            $metadata = [
                ...$planMetadata,
                'delivery_context' => $explicit ? 'explicit_resend' : 'receipt',
                'unit_role' => $textFallback || $sequence > 1 ? 'receipt_chunk' : 'invoice_caption',
                'receipt_chunk_index' => $sequence - 1,
            ];
            if (! $textFallback) {
                $metadata['attachment_type'] = 'photo';
                $metadata['attachment_present'] = true;
                $metadata['receipt_actions_version'] = self::RECEIPT_ACTIONS_OPT_IN_VERSION;
            }
            if ($explicit) {
                $metadata['duplicate_risk_acknowledged'] = true;
                if ($supersedes !== null) {
                    $metadata['supersedes_effect_id'] = (string) $supersedes->getKey();
                }
            }

            $effect = TelegramOutboundEffect::query()->firstOrCreate(
                ['effect_key' => self::receiptEffectKeyForGroup($groupKey, $sequence)],
                [
                    'effect_group_key' => $groupKey,
                    'supersedes_effect_id' => $sequence === 1 ? $supersedes?->getKey() : null,
                    'sequence' => $sequence,
                    'effect_type' => self::TYPE_RECEIPT_CREDENTIALS,
                    'source_type' => Order::class,
                    'source_id' => (string) $order->getKey(),
                    'target_type' => 'customer',
                    'telegram_chat_id' => (string) $customer->telegram_id,
                    'queue' => self::RECEIPT_QUEUE,
                    'telegram_method' => $textFallback
                        ? 'sendMessage'
                        : ($sequence === 1 ? 'sendPhoto' : 'sendMessage'),
                    'locale' => $customer->locale,
                    'template_version' => $template,
                    'state' => TelegramOutboundEffectState::Pending->value,
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                    'outcome_metadata' => $metadata,
                ],
            );

            $first ??= $effect;
        }

        return $first ?? throw new RuntimeException('Telegram receipt effect group was empty.');
    }

    /** @return array<string, int|string|bool> */
    private function receiptPlanMetadata(TelegramReceiptPlan $plan, bool $textFallback): array
    {
        $units = $textFallback ? $plan->textUnits() : $plan->photoUnits();
        $continuationLength = 0;
        foreach (array_slice($units, 1) as $unit) {
            $continuationLength = max($continuationLength, $unit->characterLength());
        }

        return [
            'receipt_chunk_count' => count($units),
            'product_count' => $plan->productCount(),
            'account_count' => $plan->accountCount(),
            'caption_length_bucket' => $this->receiptLengthBucket($plan->photoUnit(1)->characterLength()),
            'continuation_length_bucket' => $this->receiptLengthBucket($continuationLength),
            'has_photo' => ! $textFallback,
            'has_header' => true,
            'has_footer' => true,
        ];
    }

    private function receiptLengthBucket(int $length): string
    {
        return match (true) {
            $length <= 1024 => '0-1024',
            $length <= 2048 => '1025-2048',
            $length <= 3072 => '2049-3072',
            default => '3073-4096',
        };
    }

    private function createReceiptRenderFailureEffect(
        Order $order,
        Customer $customer,
        string $groupKey,
        bool $explicit,
        ?TelegramOutboundEffect $supersedes,
    ): TelegramOutboundEffect {
        $metadata = [
            'delivery_context' => $explicit ? 'explicit_resend' : 'receipt',
            'unit_role' => 'receipt_render_failure',
            'receipt_chunk_index' => 0,
            'receipt_chunk_count' => 1,
            'render_mode' => 'failed',
            'has_photo' => false,
            'has_header' => false,
            'has_footer' => false,
        ];
        if ($explicit) {
            $metadata['duplicate_risk_acknowledged'] = true;
            if ($supersedes !== null) {
                $metadata['supersedes_effect_id'] = (string) $supersedes->getKey();
            }
        }

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => self::receiptEffectKeyForGroup($groupKey, 1)],
            [
                'effect_group_key' => $groupKey,
                'supersedes_effect_id' => $supersedes?->getKey(),
                'sequence' => 1,
                'effect_type' => self::TYPE_RECEIPT_CREDENTIALS,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'customer',
                'telegram_chat_id' => (string) $customer->telegram_id,
                'queue' => self::RECEIPT_QUEUE,
                'telegram_method' => 'sendMessage',
                'locale' => $customer->locale,
                'template_version' => self::TEMPLATE_RECEIPT_TEXT_FALLBACK,
                'state' => TelegramOutboundEffectState::Failed->value,
                'attempts' => 0,
                'next_attempt_at' => null,
                'failed_at' => now(),
                'last_error_code' => 'receipt_render_changed',
                'last_error' => 'Telegram receipt rendering requires manual review.',
                'outcome_metadata' => $metadata,
            ],
        );
    }

    private function recordAdminOrderNotification(
        Order $order,
        string $effectType,
        string $templateVersion,
        string $legacySentColumn,
        ?string $chatId = null,
    ): ?TelegramOutboundEffect {
        $chatId = $this->adminTargetChatId($chatId);
        if ($chatId === null || blank(config('services.telegram.token'))) {
            return null;
        }

        $effectKey = self::adminOrderEffectKey((string) $order->getKey(), $effectType);
        $existing = TelegramOutboundEffect::query()->where('effect_key', $effectKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($order->{$legacySentColumn} !== null) {
            return null;
        }

        return TelegramOutboundEffect::query()->firstOrCreate(
            ['effect_key' => $effectKey],
            [
                'effect_group_key' => $effectKey,
                'sequence' => 1,
                'effect_type' => $effectType,
                'source_type' => Order::class,
                'source_id' => (string) $order->getKey(),
                'target_type' => 'admin',
                'telegram_chat_id' => $chatId,
                'queue' => 'notifications',
                'telegram_method' => 'sendMessage',
                'locale' => null,
                'template_version' => $templateVersion,
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
                'outcome_metadata' => [
                    'delivery_context' => 'order_lifecycle',
                ],
            ],
        );
    }

    private function adminTargetChatId(?string $chatId = null): ?string
    {
        $chatId = trim((string) ($chatId ?? $this->adminAccess->adminChatId()));

        return preg_match('/^[1-9][0-9]*$/', $chatId) === 1 ? $chatId : null;
    }

    private function salesProofProjectionState(SalesProofPost $post): string
    {
        return match ($post->status) {
            SalesProofPost::STATUS_SENT => TelegramOutboundEffectState::Sent->value,
            SalesProofPost::STATUS_FAILED => TelegramOutboundEffectState::Failed->value,
            SalesProofPost::STATUS_SENDING => TelegramOutboundEffectState::Unknown->value,
            SalesProofPost::STATUS_UNKNOWN => TelegramOutboundEffectState::Unknown->value,
            SalesProofPost::STATUS_MANUAL_REVIEW => TelegramOutboundEffectState::ManualReview->value,
            default => TelegramOutboundEffectState::Pending->value,
        };
    }

    private function markSalesProofUnpublishable(SalesProofPost $post, string $message): void
    {
        if (in_array($post->status, [
            SalesProofPost::STATUS_SENT,
            SalesProofPost::STATUS_UNKNOWN,
            SalesProofPost::STATUS_MANUAL_REVIEW,
        ], true)) {
            return;
        }

        $post->forceFill([
            'status' => SalesProofPost::STATUS_FAILED,
            'attempts' => ((int) $post->attempts) + 1,
            'last_error' => mb_substr($message, 0, 1000),
        ])->save();
    }

    private function synchronizeSalesProofProjection(TelegramOutboundEffect $effect, SalesProofPost $post): void
    {
        $state = $this->stateValue($effect);

        if ($state === TelegramOutboundEffectState::Unknown->value && $post->status !== SalesProofPost::STATUS_UNKNOWN) {
            $this->markSalesProofUnknown(
                $effect,
                $effect->unknown_at ?? $post->updated_at ?? now(),
                $effect->last_error ?: 'Sales Proof delivery outcome is unknown and requires review.',
            );
        } elseif ($state === TelegramOutboundEffectState::ManualReview->value && $post->status !== SalesProofPost::STATUS_MANUAL_REVIEW) {
            $this->markSalesProofManualReview($effect, $effect->manual_review_at ?? $post->updated_at ?? now());
        } elseif ($state === TelegramOutboundEffectState::Sent->value
            && $post->status !== SalesProofPost::STATUS_SENT
            && filled($effect->telegram_message_id)) {
            $this->markSalesProofSent($effect, (string) $effect->telegram_message_id, $effect->sent_at ?? now());
        } elseif ($state === TelegramOutboundEffectState::Failed->value) {
            // The ledger is authoritative even when an older projection was
            // never moved to `sending` before the worker failed.
            $this->markSalesProofFailed($effect);
        }
    }

    private function markSalesProofUnknown(TelegramOutboundEffect $effect, CarbonInterface $at, string $message): void
    {
        if (! in_array($effect->effect_type, self::SALES_PROOF_EFFECT_TYPES, true)) {
            return;
        }

        SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->update([
                'status' => SalesProofPost::STATUS_UNKNOWN,
                'attempts' => (int) $effect->attempts,
                'last_error' => mb_substr($message, 0, 1000),
                'updated_at' => $at,
            ]);
    }

    private function markSalesProofManualReview(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        if (! in_array($effect->effect_type, self::SALES_PROOF_EFFECT_TYPES, true)) {
            return;
        }

        SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->update([
                'status' => SalesProofPost::STATUS_MANUAL_REVIEW,
                'attempts' => (int) $effect->attempts,
                'last_error' => 'Sales Proof delivery requires manual review.',
                'updated_at' => $at,
            ]);
    }

    private function markSalesProofSent(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->update([
                'status' => SalesProofPost::STATUS_SENT,
                'attempts' => (int) $effect->attempts,
                'telegram_message_id' => $messageId,
                'sent_at' => $at,
                'last_error' => null,
                'updated_at' => now(),
            ]);
    }

    private function markSalesProofFailed(TelegramOutboundEffect $effect): void
    {
        SalesProofPost::query()
            ->where('order_id', $effect->source_id)
            ->whereNotIn('status', [
                SalesProofPost::STATUS_SENT,
                SalesProofPost::STATUS_UNKNOWN,
                SalesProofPost::STATUS_MANUAL_REVIEW,
            ])
            ->update([
                'status' => SalesProofPost::STATUS_FAILED,
                'attempts' => (int) $effect->attempts,
                'last_error' => mb_substr((string) ($effect->last_error ?: 'Sales Proof delivery failed before completion.'), 0, 1000),
                'updated_at' => now(),
            ]);
    }

    private function latestReceiptEffect(Order $order): ?TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()
            ->where('source_type', Order::class)
            ->where('source_id', (string) $order->getKey())
            ->where('effect_type', self::TYPE_RECEIPT_CREDENTIALS)
            ->orderByDesc('created_at')
            ->orderByDesc('sequence')
            ->first();
    }

    private function receiptEligible(Order $order, ?Customer $customer): bool
    {
        return $order->payment_status === PaymentStatus::Paid
            && in_array($order->order_status, [OrderStatus::PendingPayment, OrderStatus::Processing, OrderStatus::Completed], true)
            && $customer?->telegram_id !== null;
    }

    private function receiptActionPanelSource(TelegramOutboundEffect $effect): bool
    {
        if ($effect->effect_type !== self::TYPE_RECEIPT_CREDENTIALS
            || $effect->source_type !== Order::class
            || $effect->template_version !== self::TEMPLATE_RECEIPT_PHOTO_CAPTION
            || $effect->effect_group_key === null) {
            return false;
        }

        $groupKey = (string) $effect->effect_group_key;
        $orderId = (string) $effect->source_id;
        $baseGroupKey = self::receiptEffectGroupKey($orderId);

        return $groupKey === $baseGroupKey
            || preg_match('/\A'.preg_quote($baseGroupKey, '/').':resend:v[1-9][0-9]*\z/D', $groupKey) === 1;
    }

    private function receiptActionPanelEligible(Order $order, ?Customer $customer): bool
    {
        if (! $this->receiptEligible($order, $customer)
            || ! preg_match('/\A[1-9][0-9]*\z/D', trim((string) $customer?->telegram_id))) {
            return false;
        }

        if ($order->hasOpenLatePaymentReview()) {
            return false;
        }

        $payment = $order->payments
            ->sortByDesc(fn (Payment $candidate): array => [
                $candidate->created_at?->getTimestamp() ?? 0,
                (string) $candidate->getKey(),
            ])
            ->first();

        return $payment instanceof Payment
            && (string) $payment->order_id === (string) $order->getKey()
            && $payment->status === PaymentStatus::Paid
            && $payment->paid_at !== null;
    }

    private function receiptTxtDownloadEligible(Order $order, ?Customer $customer): bool
    {
        return trim((string) $customer?->telegram_id) !== ''
            && $this->credentialEligibility->allows($order, $customer);
    }

    /** @return array{0: int, 1: int} */
    private function orderSummaryCounts(Order $order): array
    {
        $itemCount = 0;

        foreach ($order->orderItems as $item) {
            $quantity = $item->getAttribute('quantity');
            if (! is_int($quantity)) {
                $quantity = filter_var($quantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            }
            if (! is_int($quantity) || $itemCount > PHP_INT_MAX - $quantity) {
                throw new InvalidArgumentException('TXT Order quantity is invalid.');
            }

            $itemCount += $quantity;
        }

        return [$order->orderItems->count(), $itemCount];
    }

    private function optionalNonNegativeCount(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        try {
            return $this->safeTxtMetadataCount($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /** @param array<string, mixed> $metadata @return array<string, string> */
    private function safePanelSizeMetadata(array $metadata): array
    {
        $safe = [];
        foreach (['copy_length_bucket', 'keyboard_length_bucket'] as $key) {
            $value = $metadata[$key] ?? null;
            if (is_string($value) && preg_match('/\A[0-9-]{1,24}\z/D', $value) === 1) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, int|string>
     */
    private function safeReceiptTxtDownloadMetadata(array $metadata, string $requestNonce): array
    {
        $allowed = [
            'delivery_context',
            'format',
            'product_count',
            'item_count',
            'byte_size_bucket',
            'origin_message_id',
            'confirmation_nonce',
            'correlation_id',
        ];
        foreach (array_keys($metadata) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw new InvalidArgumentException('TXT download metadata is not safe.');
            }
        }

        if (array_key_exists('delivery_context', $metadata) && $metadata['delivery_context'] !== 'explicit_txt_download') {
            throw new InvalidArgumentException('TXT download metadata is not safe.');
        }
        if (array_key_exists('format', $metadata) && $metadata['format'] !== 'txt') {
            throw new InvalidArgumentException('TXT download metadata is not safe.');
        }

        $safe = [
            'delivery_context' => 'explicit_txt_download',
            'format' => 'txt',
            'confirmation_nonce' => $requestNonce,
        ];

        foreach (['product_count', 'item_count'] as $key) {
            if (array_key_exists($key, $metadata) && $metadata[$key] !== null) {
                $safe[$key] = $this->safeTxtMetadataCount($metadata[$key]);
            }
        }

        if (array_key_exists('byte_size_bucket', $metadata) && $metadata['byte_size_bucket'] !== null) {
            $safe['byte_size_bucket'] = $this->safeTxtMetadataReference($metadata['byte_size_bucket']);
        }

        if (array_key_exists('origin_message_id', $metadata) && $metadata['origin_message_id'] !== null) {
            $originMessageId = $this->safeTxtMetadataCount($metadata['origin_message_id']);
            if ($originMessageId < 1) {
                throw new InvalidArgumentException('TXT download metadata is not safe.');
            }
            $safe['origin_message_id'] = $originMessageId;
        }

        if (array_key_exists('confirmation_nonce', $metadata) && $metadata['confirmation_nonce'] !== null) {
            $confirmationNonce = $this->safeTxtMetadataReference($metadata['confirmation_nonce']);
            if (! hash_equals($requestNonce, $confirmationNonce)) {
                throw new InvalidArgumentException('TXT download metadata is not safe.');
            }
            $safe['confirmation_nonce'] = $confirmationNonce;
        }

        if (array_key_exists('correlation_id', $metadata) && $metadata['correlation_id'] !== null) {
            $safe['correlation_id'] = $this->safeTxtMetadataReference($metadata['correlation_id']);
        }

        return $safe;
    }

    private function safeTxtMetadataCount(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value)
            && preg_match('/\A[0-9]{1,18}\z/D', $value) === 1
            && filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) !== false) {
            return (int) $value;
        }

        throw new InvalidArgumentException('TXT download metadata is not safe.');
    }

    private function safeTxtMetadataReference(mixed $value): string
    {
        if (! is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}\z/D', $value) !== 1) {
            throw new InvalidArgumentException('TXT download metadata is not safe.');
        }

        return $value;
    }

    private static function normalizeTxtDownloadNonce(string $requestNonce): string
    {
        $requestNonce = trim($requestNonce);
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,127}\z/D', $requestNonce) !== 1) {
            throw new InvalidArgumentException('TXT download request reference is invalid.');
        }

        return $requestNonce;
    }

    private static function receiptEffectKeyForGroup(string $groupKey, int $sequence): string
    {
        return $groupKey.':unit:'.$sequence;
    }

    private function isReviewable(TelegramOutboundEffect $effect): bool
    {
        return in_array($this->stateValue($effect), self::REVIEW_STATES, true);
    }

    private function stateValue(TelegramOutboundEffect $effect): string
    {
        return $effect->state instanceof TelegramOutboundEffectState
            ? $effect->state->value
            : (string) $effect->getRawOriginal('state');
    }

    private function sourceReference(TelegramOutboundEffect $effect): string
    {
        if ($effect->source_type === Order::class) {
            $invoice = Order::query()->whereKey($effect->source_id)->value('invoice_number');
            if (is_string($invoice) && $invoice !== '') {
                return 'Order '.$invoice;
            }
        }

        if ($effect->source_type === Payment::class) {
            $invoice = Payment::query()
                ->whereKey($effect->source_id)
                ->join('orders', 'orders.id', '=', 'payments.order_id')
                ->value('orders.invoice_number');
            if (is_string($invoice) && $invoice !== '') {
                return 'Payment proof '.$invoice;
            }
        }

        if ($effect->source_type === TelegramBroadcastRecipient::class) {
            $broadcastId = TelegramBroadcastRecipient::query()
                ->whereKey($effect->source_id)
                ->value('broadcast_id');
            if ($broadcastId !== null) {
                return 'Admin Broadcast #'.$broadcastId.' recipient #'.substr((string) $effect->source_id, 0, 8);
            }
        }

        if ($effect->source_type === StockNotificationRecipient::class) {
            $movementId = StockNotificationRecipient::query()
                ->whereKey($effect->source_id)
                ->join('stock_notification_events', 'stock_notification_events.id', '=', 'stock_notification_recipients.stock_notification_event_id')
                ->value('stock_notification_events.inventory_movement_id');
            if ($movementId !== null) {
                return 'Stock notification movement #'.$movementId.' recipient #'.substr((string) $effect->source_id, 0, 8);
            }
        }

        if ($effect->source_type === ProductPriceChangeNotificationRecipient::class) {
            $eventId = ProductPriceChangeNotificationRecipient::query()
                ->whereKey($effect->source_id)
                ->value('event_id');
            if ($eventId !== null) {
                return 'Price change #'.$eventId.' recipient #'.substr((string) $effect->source_id, 0, 8);
            }
        }

        if ($effect->source_type === ProductPriceChangeEvent::class) {
            return 'Price change #'.$effect->source_id.' Store Channel';
        }

        $sourceType = class_basename((string) $effect->source_type);
        $sourceId = substr((string) $effect->source_id, 0, 8);

        return $sourceType.' #'.$sourceId;
    }

    private function redactedTarget(TelegramOutboundEffect $effect): string
    {
        $chatId = trim((string) $effect->telegram_chat_id);
        if ($chatId === '') {
            return (string) ($effect->target_type ?: 'telegram').' (not set)';
        }

        $digits = ltrim($chatId, '+-');
        $suffix = substr($digits, -4);

        return (string) ($effect->target_type ?: 'telegram').' ••••'.$suffix;
    }

    private function safeErrorCode(?string $errorCode): ?string
    {
        $errorCode = strtolower(trim((string) $errorCode));
        if ($errorCode === '') {
            return null;
        }

        return preg_match('/\\A[a-z0-9][a-z0-9_.:-]{0,79}\\z/', $errorCode) === 1
            ? $errorCode
            : 'redacted_error_code';
    }

    /** @param array<array-key, mixed> $metadata */
    private function safeReviewMetadata(array $metadata): array
    {
        $safe = [];

        foreach (self::SAFE_REVIEW_METADATA_KEYS as $key) {
            if (! array_key_exists($key, $metadata)) {
                continue;
            }

            $value = $metadata[$key];
            if ($key === 'duplicate_risk_acknowledged' && is_bool($value)) {
                $safe[$key] = $value;
            } elseif (in_array($key, [
                'http_status',
                'resolution_actor_id',
                'receipt_chunk_index',
                'receipt_chunk_count',
                'product_count',
                'item_count',
                'origin_message_id',
                'account_count',
                'action_count',
            ], true)
                && ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value)))) {
                $safe[$key] = (int) $value;
            } elseif (in_array($key, ['attachment_present', 'late_evidence', 'has_photo', 'has_header', 'has_footer', 'txt_available'], true)
                && is_bool($value)) {
                $safe[$key] = $value;
            } elseif ($key === 'exception' && is_string($value)
                && preg_match('/\A[A-Za-z_][A-Za-z0-9_\\\\]*\z/', $value) === 1) {
                $safe[$key] = class_basename($value);
            } elseif (in_array($key, [
                'reason',
                'delivery_context',
                'unit_role',
                'resolution',
                'attachment_type',
                'payment_method',
                'proof_status',
                'caption_length_bucket',
                'continuation_length_bucket',
                'render_mode',
                'format',
                'byte_size_bucket',
                'output_size_bucket',
                'render_duration_bucket',
                'telegram_document_duration_bucket',
                'queue_wait_bucket',
                'effect_completion_duration_bucket',
                'confirmation_nonce',
                'correlation_id',
                'delivery_method',
                'receipt_group_key',
                'receipt_actions_version',
                'copy_length_bucket',
                'keyboard_length_bucket',
            ], true) && is_string($value)) {
                $safeValue = $this->safeErrorCode($value);
                if ($safeValue !== null) {
                    $safe[$key] = $safeValue;
                }
            } elseif ($key === 'telegram_response' && $value === 'ok') {
                $safe[$key] = 'ok';
            } elseif ($key === 'resolution_at' && is_string($value)
                && preg_match('/\\A[0-9TZ: .+\\-]{1,40}\\z/', $value) === 1) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /** @return array<string, mixed> */
    private function mergeOutcomeMetadata(TelegramOutboundEffect $effect, array $updates): array
    {
        $existing = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return array_merge($existing, $updates);
    }

    private static function templateVersion(string $effectType): string
    {
        return match ($effectType) {
            self::TYPE_MANUAL_CHECKOUT => self::TEMPLATE_MANUAL_CHECKOUT,
            self::TYPE_KLIKQRIS_CHECKOUT => self::TEMPLATE_KLIKQRIS_CHECKOUT,
            self::TYPE_SAKURUPIAH_CHECKOUT => self::TEMPLATE_SAKURUPIAH_CHECKOUT,
            self::TYPE_PAKASIR_CHECKOUT => self::TEMPLATE_PAKASIR_CHECKOUT,
            self::TYPE_BINANCE_CHECKOUT => self::TEMPLATE_BINANCE_CHECKOUT,
            self::TYPE_BSC_CHECKOUT => self::TEMPLATE_BSC_CHECKOUT,
            self::TYPE_RECEIPT_CREDENTIALS => self::TEMPLATE_RECEIPT_CREDENTIALS,
            self::TYPE_RECEIPT_TXT_DOWNLOAD => self::TEMPLATE_RECEIPT_TXT_DOWNLOAD,
            self::TYPE_RECEIPT_ORDER_ACTIONS => self::TEMPLATE_RECEIPT_ORDER_ACTIONS,
            self::TYPE_ADMIN_PENDING => self::TEMPLATE_ADMIN_ORDER_PENDING,
            self::TYPE_ADMIN_PAID => self::TEMPLATE_ADMIN_ORDER_PAID,
            self::TYPE_ADMIN_EXPIRED => self::TEMPLATE_ADMIN_ORDER_EXPIRED,
            self::TYPE_ADMIN_BROADCAST => self::TEMPLATE_ADMIN_BROADCAST,
            self::TYPE_MANUAL_PAYMENT_PROOF => self::TEMPLATE_MANUAL_PAYMENT_PROOF,
            self::TYPE_SALES_PROOF => self::TEMPLATE_SALES_PROOF,
            self::TYPE_STOCK_NOTIFICATION => self::TEMPLATE_STOCK_NOTIFICATION,
            self::TYPE_PRICE_CHANGE_NOTIFICATION => self::TEMPLATE_PRICE_CHANGE_NOTIFICATION,
            self::TYPE_PRICE_CHANGE_STORE_CHANNEL => self::TEMPLATE_PRICE_CHANGE_STORE_CHANNEL,
            default => 'checkout-payment-v1',
        };
    }
}
