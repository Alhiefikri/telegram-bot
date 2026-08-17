<?php

namespace App\Services\Telegram;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Exceptions\TelegramOutboundPreSendException;
use App\Exceptions\TelegramTransportException;
use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Jobs\ForwardManualPaymentProof;
use App\Jobs\PublishSalesProofPost;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Jobs\SendTelegramReceipt;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\TelegramBroadcast;
use App\Models\TelegramBroadcastRecipient;
use App\Models\TelegramOutboundEffect;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InventoryLedger;
use App\Storefront\StorefrontUrl;
use App\Support\DogfoodTiming;
use App\Support\PaymentProvider;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TelegramOutboundEffectService
{
    public const TYPE_MANUAL_CHECKOUT = TelegramOutboundEffectType::ManualCheckoutInstructions->value;

    public const TYPE_KLIKQRIS_CHECKOUT = TelegramOutboundEffectType::KlikQrisCheckoutInstructions->value;

    public const TYPE_PAKASIR_CHECKOUT = TelegramOutboundEffectType::PakasirCheckoutInstructions->value;

    public const TYPE_BINANCE_CHECKOUT = TelegramOutboundEffectType::BinanceCheckoutInstructions->value;

    public const TYPE_RECEIPT_CREDENTIALS = TelegramOutboundEffectType::ReceiptCredentials->value;

    public const TYPE_ADMIN_PENDING = TelegramOutboundEffectType::AdminOrderPendingNotification->value;

    public const TYPE_ADMIN_PAID = TelegramOutboundEffectType::AdminOrderPaidNotification->value;

    public const TYPE_ADMIN_EXPIRED = TelegramOutboundEffectType::AdminOrderExpiredNotification->value;

    public const TYPE_ADMIN_BROADCAST = TelegramOutboundEffectType::AdminBroadcastRecipient->value;

    /** Compatibility alias for callers that spell out the recipient unit. */
    public const TYPE_ADMIN_BROADCAST_RECIPIENT = self::TYPE_ADMIN_BROADCAST;

    public const TYPE_MANUAL_PAYMENT_PROOF = TelegramOutboundEffectType::ManualPaymentProof->value;

    public const TYPE_SALES_PROOF = TelegramOutboundEffectType::SalesProofPost->value;

    /** Compatibility aliases for callers that use the longer effect names. */
    public const TYPE_ADMIN_ORDER_PENDING_NOTIFICATION = self::TYPE_ADMIN_PENDING;

    public const TYPE_ADMIN_ORDER_PAID_NOTIFICATION = self::TYPE_ADMIN_PAID;

    public const TYPE_ADMIN_ORDER_EXPIRED_NOTIFICATION = self::TYPE_ADMIN_EXPIRED;

    /** Compatibility alias for callers that refer to the receipt family directly. */
    public const TYPE_RECEIPT = self::TYPE_RECEIPT_CREDENTIALS;

    public const TEMPLATE_MANUAL_CHECKOUT = 'manual-payment-v1';

    public const TEMPLATE_KLIKQRIS_CHECKOUT = 'klikqris-payment-v1';

    public const TEMPLATE_PAKASIR_CHECKOUT = 'pakasir-payment-v1';

    public const TEMPLATE_BINANCE_CHECKOUT = 'binance-payment-v1';

    public const TEMPLATE_RECEIPT_CREDENTIALS = 'receipt-credentials-v1';

    public const TEMPLATE_ADMIN_ORDER_PENDING = 'admin-order-pending-v1';

    public const TEMPLATE_ADMIN_ORDER_PAID = 'admin-order-paid-v1';

    public const TEMPLATE_ADMIN_ORDER_EXPIRED = 'admin-order-expired-v1';

    public const TEMPLATE_ADMIN_BROADCAST = 'admin-broadcast-v1';

    public const TEMPLATE_MANUAL_PAYMENT_PROOF = 'manual-payment-proof-v1';

    public const TEMPLATE_SALES_PROOF = 'sales-proof-post-v1';

    public const RECEIPT_QUEUE = 'notifications';

    public const SALES_PROOF_QUEUE = 'notifications';

    private const SALES_PROOF_MAX_ATTEMPTS = 3;

    /** @var list<string> */
    private const CHECKOUT_EFFECT_TYPES = [
        self::TYPE_MANUAL_CHECKOUT,
        self::TYPE_KLIKQRIS_CHECKOUT,
        self::TYPE_PAKASIR_CHECKOUT,
        self::TYPE_BINANCE_CHECKOUT,
    ];

    /** @var list<string> */
    private const RECEIPT_EFFECT_TYPES = [
        self::TYPE_RECEIPT_CREDENTIALS,
    ];

    /** @var list<string> */
    private const ADMIN_NOTIFICATION_EFFECT_TYPES = [
        self::TYPE_ADMIN_PENDING,
        self::TYPE_ADMIN_PAID,
        self::TYPE_ADMIN_EXPIRED,
    ];

    /** @var list<string> */
    private const ADMIN_BROADCAST_EFFECT_TYPES = [
        self::TYPE_ADMIN_BROADCAST,
    ];

    public const ADMIN_BROADCAST_MAX_ATTEMPTS = 5;

    /** @var list<string> */
    private const SALES_PROOF_EFFECT_TYPES = [
        self::TYPE_SALES_PROOF,
    ];

    /** @var list<string> */
    private const NON_RETRYABLE_FAILURE_CODES = [
        'checkout_expired',
        'checkout_not_pending',
        'checkout_payment_missing',
        'checkout_payment_not_pending',
        'receipt_not_eligible',
        'receipt_render_changed',
        'admin_notification_not_eligible',
        'admin_target_missing',
        'proof_not_eligible',
        'proof_attachment_missing',
        'proof_already_forwarded',
        'proof_disabled',
        'proof_target_missing',
        'telegram_http_400',
        'telegram_http_403',
        'broadcast_recipient_attempts_exhausted',
        'broadcast_recipient_terminal_failure',
    ];

    /** @var list<string> */
    private const REVIEW_STATES = [
        TelegramOutboundEffectState::Unknown->value,
        TelegramOutboundEffectState::ManualReview->value,
    ];

    /** @var list<string> */
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
        'receipt_chunk_index',
        'receipt_chunk_count',
        'attachment_type',
        'attachment_present',
        'payment_method',
        'late_evidence',
        'proof_status',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly TelegramReceiptNotifier $receiptNotifier,
        private readonly TelegramAdminAccess $adminAccess,
        private readonly TelegramAdminOrderNotifier $adminOrderNotifier,
        private readonly TelegramAdminPaidOrderNotifier $adminPaidOrderNotifier,
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
                'telegram_method' => 'sendPhoto',
                'locale' => $customer->locale,
                'template_version' => self::templateVersion($effectType),
                'state' => TelegramOutboundEffectState::Pending->value,
                'attempts' => 0,
                'next_attempt_at' => now(),
            ],
        );
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
            legacyStartedColumn: 'admin_pending_order_notification_started_at',
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
            legacyStartedColumn: 'admin_paid_order_notification_started_at',
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
            legacyStartedColumn: 'admin_expired_order_notification_started_at',
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

        // A legacy worker may already have ownership of this proof. Do not
        // create a second delivery unit while it is still in flight.
        if ($payment->payment_proof_forwarded_at !== null
            || $payment->payment_proof_forwarding_started_at !== null) {
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
                'telegram_method' => 'sendMessage',
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
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effect->getKey());
            if ($locked->effect_type !== self::TYPE_SALES_PROOF
                || $this->stateValue($locked) !== TelegramOutboundEffectState::Failed->value) {
                throw new InvalidArgumentException('Only a failed Sales Proof effect can be retried.');
            }

            $locked->forceFill([
                'state' => TelegramOutboundEffectState::Pending->value,
                'next_attempt_at' => now(),
                'failed_at' => null,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($locked, [
                    'manual_retry_requested' => true,
                    'delivery_context' => 'sales_proof',
                ]),
            ])->save();

            SalesProofPost::query()
                ->where('order_id', $locked->source_id)
                ->where('status', SalesProofPost::STATUS_FAILED)
                ->update([
                    'status' => SalesProofPost::STATUS_PENDING,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return $locked->fresh();
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

    /**
     * Dispatch the pending admin effect created during checkout. A false
     * result tells expand-phase callers to retain their legacy fallback job.
     */
    public function dispatchPendingAdminNotification(Order $order): bool
    {
        $effect = $this->forOrder((string) $order->getKey(), self::TYPE_ADMIN_PENDING);
        if ($effect === null) {
            return false;
        }

        if ($this->isDispatchable($effect)) {
            $this->dispatch($effect);
        }

        // An existing effect, including a sent or review state, is authoritative
        // even when it is not currently dispatchable. Do not enqueue a legacy
        // fallback that could race the ledger owner.
        return true;
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

            $groupKey = self::receiptEffectGroupKey((string) $locked->getKey());
            $existing = TelegramOutboundEffect::query()
                ->where('effect_group_key', $groupKey)
                ->orderBy('sequence')
                ->first();

            if ($existing !== null) {
                return $this->firstReadyReceiptEffect($groupKey);
            }

            // A legacy claim is evidence that a pre-ledger worker may already
            // have called Telegram. Do not silently backfill it as pending.
            if ($locked->telegram_receipt_sent_at !== null
                || $locked->telegram_receipt_send_started_at !== null) {
                return null;
            }

            return $this->createReceiptEffectGroup($locked, $customer);
        }, 5);
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
        return 'order:'.$orderId.':'.self::TYPE_RECEIPT_CREDENTIALS.':v1';
    }

    public static function receiptEffectKey(string $orderId, int $sequence): string
    {
        return self::receiptEffectGroupKey($orderId).':unit:'.$sequence;
    }

    public static function typeForPayment(Payment $payment): ?string
    {
        return match (true) {
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'QR_MANUAL' => self::TYPE_MANUAL_CHECKOUT,
            $payment->provider === PaymentProvider::KLIKQRIS => self::TYPE_KLIKQRIS_CHECKOUT,
            $payment->provider === PaymentProvider::PAKASIR => self::TYPE_PAKASIR_CHECKOUT,
            $payment->provider === PaymentProvider::MANUAL && $payment->method === 'BINANCE_PAY_ID' => self::TYPE_BINANCE_CHECKOUT,
            default => null,
        };
    }

    public static function checkoutEffectKey(string $orderId, string $effectType): string
    {
        return 'order:'.$orderId.':'.$effectType.':v1';
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
            'effect' => $effect,
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

    public function markSent(TelegramOutboundEffect $effect, string $messageId, ?User $actor = null, ?string $ip = null): bool
    {
        return DB::transaction(function () use ($effect, $messageId, $actor, $ip): bool {
            $locked = TelegramOutboundEffect::query()->lockForUpdate()->find($effect->getKey());
            if ($locked === null || ! $this->isReviewable($locked)) {
                return false;
            }

            $oldState = $this->stateValue($locked);
            $now = now();
            $locked->forceFill([
                'state' => TelegramOutboundEffectState::Sent->value,
                'lease_until' => null,
                'claim_token' => null,
                'telegram_message_id' => $messageId,
                'sent_at' => $now,
                'manual_review_at' => $locked->manual_review_at ?? $now,
                'failed_at' => null,
                'next_attempt_at' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($locked, [
                    'resolution' => 'operator_marked_sent',
                    'resolution_actor_id' => $actor?->getKey(),
                    'resolution_at' => $now->toIso8601String(),
                ]),
            ])->save();

            $this->markLegacySent($locked, $messageId, $now);
            $this->auditLogger->logWithResolvedContext(
                'telegram_outbound_effect.marked_sent',
                $locked,
                ['state' => $oldState],
                [
                    'state' => TelegramOutboundEffectState::Sent->value,
                    'telegram_message_id' => $messageId,
                    'resolution' => 'operator_marked_sent',
                ],
                $actor,
                $ip,
            );

            if ($locked->effect_type === self::TYPE_RECEIPT_CREDENTIALS) {
                $next = $this->nextReceiptEffect($locked);
                if ($next !== null) {
                    $this->dispatch($next);
                } elseif ($this->isExplicitReceiptEffect($locked)) {
                    $this->dispatchReceiptCleanup($locked->source_id);
                }
            }

            return true;
        });
    }

    public function resend(TelegramOutboundEffect $effect, User $actor, ?string $ip = null): TelegramOutboundEffect
    {
        if ($effect->effect_type === self::TYPE_RECEIPT_CREDENTIALS) {
            return $this->resendReceiptEffect($effect, $actor, $ip);
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

    public function dispatch(TelegramOutboundEffect $effect, ?string $jobEnqueuedAt = null, bool $force = false): void
    {
        if (! $force && ! $this->isDispatchable($effect)) {
            return;
        }

        $jobEnqueuedAt ??= now()->toISOString();
        $pending = match ($effect->effect_type) {
            self::TYPE_MANUAL_CHECKOUT => SendManualCheckoutNotifications::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_KLIKQRIS_CHECKOUT => SendKlikQrisCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_PAKASIR_CHECKOUT => SendPakasirCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_BINANCE_CHECKOUT => SendBinanceCheckoutInstructions::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_RECEIPT_CREDENTIALS => SendTelegramReceipt::dispatch(
                (string) $effect->source_id,
                $force,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_ADMIN_PENDING => SendAdminOrderPendingNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_ADMIN_PAID => SendAdminOrderPaidNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_ADMIN_EXPIRED => SendAdminOrderExpiredNotification::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_ADMIN_BROADCAST => SendTelegramBroadcastBatch::dispatch(
                (int) TelegramBroadcastRecipient::query()
                    ->whereKey($effect->source_id)
                    ->value('broadcast_id'),
                $jobEnqueuedAt,
            ),
            self::TYPE_MANUAL_PAYMENT_PROOF => ForwardManualPaymentProof::dispatch(
                (string) $effect->source_id,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            self::TYPE_SALES_PROOF => PublishSalesProofPost::dispatch(
                (string) $effect->source_id,
                false,
                $jobEnqueuedAt,
                (string) $effect->getKey(),
            ),
            default => null,
        };

        $pending?->onQueue((string) ($effect->queue ?: TelegramOutboundEffect::CHECKOUT_QUEUE))->afterCommit();
    }

    public function dispatchExplicitResend(TelegramOutboundEffect $effect): void
    {
        $this->dispatch($effect, force: true);
    }

    /** @return Builder<TelegramOutboundEffect> */
    public function readyToDispatchQuery(?CarbonInterface $at = null): Builder
    {
        $at ??= now();

        return TelegramOutboundEffect::query()
            ->where(function (Builder $query): void {
                $query
                    ->where('state', TelegramOutboundEffectState::Pending->value)
                    ->orWhere(function (Builder $failed): void {
                        $failed
                            ->where('state', TelegramOutboundEffectState::Failed->value)
                            ->where(function (Builder $error): void {
                                $error
                                    ->whereNull('last_error_code')
                                    ->orWhereNotIn('last_error_code', self::NON_RETRYABLE_FAILURE_CODES);
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', self::TYPE_SALES_PROOF)
                    ->orWhere(function (Builder $salesProof): void {
                        $salesProof->where('effect_type', self::TYPE_SALES_PROOF)
                            ->where(function (Builder $attempts): void {
                                $attempts
                                    ->where('attempts', '<', self::SALES_PROOF_MAX_ATTEMPTS)
                                    ->orWhereJsonContains('outcome_metadata', ['manual_retry_requested' => true]);
                            });
                    });
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', self::TYPE_ADMIN_BROADCAST)
                    ->orWhere('attempts', '<', self::ADMIN_BROADCAST_MAX_ATTEMPTS);
            })
            ->where(function (Builder $query) use ($at): void {
                $query->whereNull('next_attempt_at')
                    ->orWhere('next_attempt_at', '<=', $at);
            })
            ->where(function (Builder $query): void {
                $query
                    ->where('effect_type', '<>', self::TYPE_RECEIPT_CREDENTIALS)
                    ->orWhere(function (Builder $receipt): void {
                        $receipt
                            ->where('effect_type', self::TYPE_RECEIPT_CREDENTIALS)
                            ->where(function (Builder $sequence): void {
                                $sequence
                                    ->where('sequence', '<=', 1)
                                    ->orWhereNotExists(function ($predecessor): void {
                                        $predecessor
                                            ->selectRaw('1')
                                            ->from('telegram_outbound_effects as receipt_predecessors')
                                            ->whereColumn('receipt_predecessors.effect_group_key', 'telegram_outbound_effects.effect_group_key')
                                            ->whereColumn('receipt_predecessors.sequence', '<', 'telegram_outbound_effects.sequence')
                                            ->where('receipt_predecessors.state', '<>', TelegramOutboundEffectState::Sent->value);
                                    });
                            });
                    });
            });
    }

    public function claim(string $effectId): ?TelegramOutboundEffect
    {
        return DB::transaction(function () use ($effectId): ?TelegramOutboundEffect {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->findOrFail($effectId);
            $now = now();

            if (! $this->isClaimable($effect, $now)) {
                return null;
            }

            if (! $this->receiptEffectIsOrderedReady($effect)) {
                return null;
            }

            $token = Str::random(64);
            $leaseSeconds = max(60, (int) config('services.telegram.claim_lease_seconds', 600));

            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sending->value,
                'attempts' => $effect->attempts + 1,
                'next_attempt_at' => null,
                'lease_until' => $now->copy()->addSeconds($leaseSeconds),
                'claim_token' => $token,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $effect->effect_type === self::TYPE_SALES_PROOF
                    ? $this->mergeOutcomeMetadata($effect, ['manual_retry_requested' => false])
                    : $effect->outcome_metadata,
            ])->save();

            $this->markLegacyClaim($effect, $now);

            return $effect->fresh();
        });
    }

    public function processCheckout(TelegramOutboundEffect $effect, TelegramCheckoutIntents $checkoutIntents): void
    {
        $claimed = $this->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()->with(['customer', 'orderItems'])->findOrFail($claimed->source_id);
            if ($order->order_status !== OrderStatus::PendingPayment
                || ($order->expires_at !== null && $order->expires_at->lte(now()))) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $order->expires_at !== null && $order->expires_at->lte(now())
                        ? 'checkout_expired'
                        : 'checkout_not_pending',
                );

                return;
            }

            $payment = $this->paymentForCheckoutEffect($order, $claimed);
            if ($payment === null || $payment->status !== PaymentStatus::Pending) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $payment === null ? 'checkout_payment_missing' : 'checkout_payment_not_pending',
                );

                return;
            }

            $response = DogfoodTiming::time('dogfood.telegram.checkout_step', [
                'step' => $this->timingStep($claimed),
                'payment_mode' => $this->timingPaymentMode($claimed),
                'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                'order_correlation_id' => DogfoodTiming::correlation($order->id),
            ], fn () => $this->sendCheckoutInstructions($claimed, $checkoutIntents, $order, $payment));
            $messageId = data_get($response, 'result.message_id');

            if (! is_numeric($messageId)) {
                throw new RuntimeException('Telegram checkout instruction did not return a message ID.');
            }

            $this->finalizeSent((string) $claimed->getKey(), (string) $claimed->claim_token, (string) $messageId);

            if ($claimed->effect_type === self::TYPE_BINANCE_CHECKOUT) {
                $terminalOrder = $order->fresh(['payments']);
                if (in_array($terminalOrder->order_status, [OrderStatus::Completed, OrderStatus::Cancelled], true)) {
                    DeleteBuyerBinanceCheckoutInstruction::dispatch($terminalOrder->id, now()->toISOString())
                        ->onQueue('notifications');
                }
            }
        } catch (Throwable $exception) {
            if ($this->isAmbiguous($exception)) {
                $this->finalizeUnknown((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

                return;
            }

            $this->finalizeFailed((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

            throw $exception;
        }
    }

    public function processManualCheckout(TelegramOutboundEffect $effect, TelegramCheckoutIntents $checkoutIntents): void
    {
        $this->processCheckout($effect, $checkoutIntents);
    }

    public function processReceipt(
        TelegramOutboundEffect $effect,
        ?TelegramReceiptNotifier $notifier = null,
        bool $dispatchNext = true,
        bool $throwOnAmbiguous = false,
    ): void {
        if ($effect->effect_type !== self::TYPE_RECEIPT_CREDENTIALS) {
            throw new InvalidArgumentException('Unsupported Telegram receipt effect type.');
        }

        $claimed = $this->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()
                ->with(['customer', 'orderItems.stockItems', 'orderItems.product'])
                ->findOrFail($claimed->source_id);

            if (! $this->receiptEligible($order, $order->customer)
                || (string) $order->customer->telegram_id !== (string) $claimed->telegram_chat_id) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'receipt_not_eligible',
                    'Telegram receipt was no longer eligible for delivery.',
                );

                return;
            }

            $response = DogfoodTiming::time('dogfood.telegram.receipt_step', [
                'step' => 'receipt_chunk',
                'sequence' => (int) $claimed->sequence,
                'chunk_group_correlation_id' => DogfoodTiming::correlation($claimed->effect_group_key),
                'customer_correlation_id' => DogfoodTiming::correlation($order->customer->telegram_id),
                'order_correlation_id' => DogfoodTiming::correlation($order->id),
            ], fn () => ($notifier ?? $this->receiptNotifier)->sendEffect($order, $claimed));
            $messageId = data_get($response, 'result.message_id');

            if (! is_numeric($messageId)) {
                throw new TelegramTransportException('sendMessage');
            }

            if ($this->finalizeSent((string) $claimed->getKey(), (string) $claimed->claim_token, (string) $messageId)) {
                if ($dispatchNext) {
                    $next = $this->nextReceiptEffect($claimed->fresh());
                    if ($next !== null) {
                        $this->dispatch($next);
                    }
                }

                if ($this->isExplicitReceiptEffect($claimed)) {
                    $this->dispatchReceiptCleanup($order->id);
                }
            }
        } catch (Throwable $exception) {
            if ($this->isAmbiguous($exception)) {
                $this->finalizeUnknown((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

                if ($throwOnAmbiguous) {
                    throw $exception;
                }

                return;
            }

            $this->finalizeFailed((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);
        }
    }

    public function processAdminOrderNotification(TelegramOutboundEffect $effect): void
    {
        if (! in_array($effect->effect_type, self::ADMIN_NOTIFICATION_EFFECT_TYPES, true)) {
            throw new InvalidArgumentException('Unsupported Telegram admin notification effect type.');
        }

        $claimed = $this->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()
                ->with(['customer', 'orderItems', 'payments'])
                ->findOrFail($claimed->source_id);

            if (blank(config('services.telegram.token'))
                || preg_match('/^[1-9][0-9]*$/', trim((string) $claimed->telegram_chat_id)) !== 1) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'admin_target_missing',
                    'Telegram admin notification has no usable delivery target.',
                );

                return;
            }

            if (! $this->adminNotificationEligible($order, (string) $claimed->effect_type)) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'admin_notification_not_eligible',
                    'Telegram admin notification was no longer eligible for delivery.',
                );

                return;
            }

            if ($claimed->effect_type === self::TYPE_ADMIN_PAID) {
                // The pending message is a cleanup side effect, not a durable
                // business effect. Its failure must not reset the paid effect.
                $this->adminOrderNotifier->deletePending($order);
            }

            $response = match ($claimed->effect_type) {
                self::TYPE_ADMIN_PENDING => $this->adminOrderNotifier->sendPendingEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                ),
                self::TYPE_ADMIN_PAID => $this->adminPaidOrderNotifier->sendEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                ),
                self::TYPE_ADMIN_EXPIRED => $this->adminOrderNotifier->sendExpiredEffect(
                    $order,
                    (string) $claimed->telegram_chat_id,
                ),
                default => throw new InvalidArgumentException('Unsupported Telegram admin notification effect type.'),
            };
            $messageId = data_get($response, 'result.message_id');

            if (! is_numeric($messageId)) {
                throw new TelegramTransportException('sendMessage');
            }

            $this->finalizeSent((string) $claimed->getKey(), (string) $claimed->claim_token, (string) $messageId);

            if ($claimed->effect_type === self::TYPE_ADMIN_EXPIRED) {
                $this->adminOrderNotifier->deletePending($order);
            }
        } catch (Throwable $exception) {
            if ($this->isAmbiguous($exception)) {
                $this->finalizeUnknown((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

                return;
            }

            $this->finalizeFailed((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

            throw $exception;
        }
    }

    public function processManualPaymentProof(TelegramOutboundEffect $effect): void
    {
        if ($effect->effect_type !== self::TYPE_MANUAL_PAYMENT_PROOF) {
            throw new InvalidArgumentException('Unsupported Telegram manual payment proof effect type.');
        }

        $claimed = $this->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $payment = Payment::query()
                ->with(['order.customer', 'order.orderItems'])
                ->findOrFail($claimed->source_id);
            $order = $payment->order;

            if (blank(config('services.telegram.token'))
                || preg_match('/^[1-9][0-9]*$/', trim((string) $claimed->telegram_chat_id)) !== 1) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'admin_target_missing',
                    'Manual payment proof has no usable delivery target.',
                );

                return;
            }

            if ($order === null || ! $this->manualPaymentProofEligible($order, $payment)) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    $payment->payment_proof_forwarded_at !== null
                        ? 'proof_already_forwarded'
                        : 'proof_not_eligible',
                    'Manual payment proof was no longer eligible for forwarding.',
                );

                return;
            }

            if (trim((string) $claimed->telegram_chat_id) === '') {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'admin_target_missing',
                    'Manual payment proof has no configured admin target.',
                );

                return;
            }

            $photoFileId = trim((string) $payment->payment_proof_file_id);
            if ($photoFileId === '') {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'proof_attachment_missing',
                    'Manual payment proof attachment is no longer available.',
                );

                return;
            }

            $response = app(TelegramManualPaymentIntents::class)->sendManualPaymentProofEffect(
                $order,
                $payment,
                (string) $claimed->telegram_chat_id,
            );
            $messageId = data_get($response, 'result.message_id');

            if (! is_numeric($messageId)) {
                throw new TelegramTransportException('sendPhoto');
            }

            $this->finalizeSent((string) $claimed->getKey(), (string) $claimed->claim_token, (string) $messageId);
        } catch (Throwable $exception) {
            if ($this->isAmbiguous($exception)) {
                $this->finalizeUnknown((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

                return;
            }

            $this->finalizeFailed((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

            throw $exception;
        }
    }

    public function processSalesProofPost(
        TelegramOutboundEffect $effect,
        TelegramBotClient $bot,
        SalesProofPostFormatter $formatter,
        SalesProofPostNumbering $numbering,
        InventoryLedger $inventory,
    ): void {
        if ($effect->effect_type !== self::TYPE_SALES_PROOF) {
            throw new InvalidArgumentException('Unsupported Telegram Sales Proof effect type.');
        }

        $claimed = $this->claim((string) $effect->getKey());
        if ($claimed === null) {
            return;
        }

        try {
            $order = Order::query()
                ->with(['customer', 'orderItems', 'salesProofPost', 'payments'])
                ->findOrFail($claimed->source_id);
            $setting = StoreChannelSetting::current();
            $chatId = trim((string) ($setting->telegram_chat_id ?: $setting->channel_username));

            if (! $setting->sales_proof_enabled) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'proof_disabled',
                    'Sales Proof Post is disabled.',
                );

                return;
            }

            if ($chatId === '') {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'proof_target_missing',
                    'Store Channel chat is not configured.',
                );

                return;
            }

            $invariantFailure = $this->paidOrderProofInvariantFailure($order, $inventory);
            if ($invariantFailure !== null) {
                $this->finalizeNotDeliverable(
                    (string) $claimed->getKey(),
                    (string) $claimed->claim_token,
                    'proof_not_eligible',
                    $invariantFailure,
                );

                return;
            }

            $successfulPostNumber = $numbering->reserveNextNumber();
            $response = $bot->sendMessage(
                (string) $claimed->telegram_chat_id,
                $formatter->format($order, $successfulPostNumber),
                [
                    'inline_keyboard' => [[[
                        'text' => '🛒 Shop Now',
                        'url' => $this->salesProofShoppingButtonUrl($order),
                    ]]],
                ],
            );
            $messageId = data_get($response, 'result.message_id');
            if (data_get($response, 'ok') !== true || ! is_numeric($messageId)) {
                throw new RuntimeException('Telegram Sales Proof sendMessage did not return a successful message id.');
            }

            $this->finalizeSent((string) $claimed->getKey(), (string) $claimed->claim_token, (string) $messageId);
        } catch (Throwable $exception) {
            if ($this->isAmbiguous($exception)) {
                $this->finalizeUnknown((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);

                return;
            }

            $this->finalizeFailed((string) $claimed->getKey(), (string) $claimed->claim_token, $exception);
        }
    }

    public function nextReceiptEffect(TelegramOutboundEffect $effect): ?TelegramOutboundEffect
    {
        if ($effect->effect_type !== self::TYPE_RECEIPT_CREDENTIALS || $effect->effect_group_key === null) {
            return null;
        }

        $candidates = TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '>', $effect->sequence)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->orderBy('sequence')
            ->get();

        foreach ($candidates as $candidate) {
            $orderedReady = $this->receiptEffectIsOrderedReady($candidate);
            $claimable = $this->isClaimable($candidate, now());
            if ($orderedReady && $claimable) {
                return $candidate;
            }
        }

        return null;
    }

    private function createReceiptEffectGroup(
        Order $order,
        Customer $customer,
        bool $explicit = false,
        ?TelegramOutboundEffect $supersedes = null,
    ): TelegramOutboundEffect {
        $rendered = $this->receiptNotifier->render($order);
        $chunkCount = max(1, count($rendered['chunks']));
        $baseGroupKey = self::receiptEffectGroupKey((string) $order->getKey());
        $groupKey = $baseGroupKey;

        if ($explicit) {
            $version = 2;
            do {
                $groupKey = $baseGroupKey.':resend:v'.$version;
                $version++;
            } while (TelegramOutboundEffect::query()->where('effect_group_key', $groupKey)->exists());
        }

        $first = null;
        for ($sequence = 1; $sequence <= $chunkCount; $sequence++) {
            $metadata = [
                'delivery_context' => $explicit ? 'explicit_resend' : 'receipt',
                'receipt_chunk_index' => $sequence - 1,
                'receipt_chunk_count' => $chunkCount,
            ];
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
                    'telegram_method' => 'sendMessage',
                    'locale' => $customer->locale,
                    'template_version' => self::TEMPLATE_RECEIPT_CREDENTIALS,
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

    private function recordAdminOrderNotification(
        Order $order,
        string $effectType,
        string $templateVersion,
        string $legacyStartedColumn,
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

        // Legacy claim columns are compatibility evidence. If an old worker
        // has already started or completed this unit, do not backfill another
        // effect that could race it or resend its message.
        if ($order->{$legacySentColumn} !== null || $order->{$legacyStartedColumn} !== null) {
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

    private function adminNotificationEligible(Order $order, string $effectType): bool
    {
        return match ($effectType) {
            self::TYPE_ADMIN_PENDING => $order->order_status === OrderStatus::PendingPayment
                && $order->payment_status === PaymentStatus::Pending,
            self::TYPE_ADMIN_PAID => in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)
                && $order->payment_status === PaymentStatus::Paid,
            self::TYPE_ADMIN_EXPIRED => $order->order_status === OrderStatus::Expired
                && $order->payment_status === PaymentStatus::Expired,
            default => false,
        };
    }

    private function manualPaymentProofEligible(Order $order, Payment $payment): bool
    {
        $lateEvidence = $payment->method === 'BINANCE_PAY_ID'
            && in_array($payment->status, [PaymentStatus::Expired, PaymentStatus::Failed], true)
            && in_array($order->order_status, [OrderStatus::Expired, OrderStatus::Cancelled], true);

        return $payment->provider === 'manual'
            && ($payment->status === PaymentStatus::Pending || $lateEvidence)
            && $payment->payment_proof_file_id !== null
            && $payment->payment_proof_submitted_at !== null
            && $payment->payment_proof_forwarded_at === null;
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
            $this->markLegacySent(
                $effect,
                (string) $effect->telegram_message_id,
                $effect->sent_at ?? now(),
            );
        } elseif ($state === TelegramOutboundEffectState::Failed->value
            && $post->status === SalesProofPost::STATUS_SENDING) {
            $this->clearLegacyClaim($effect);
        }
    }

    private function paidOrderProofInvariantFailure(Order $order, InventoryLedger $inventory): ?string
    {
        $isPaid = $order->payment_status === PaymentStatus::Paid
            && $order->payments->contains(fn (Payment $payment): bool => $payment->status === PaymentStatus::Paid);
        $isProcessable = in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true);
        if (! $isPaid || ! $isProcessable) {
            return 'Sales Proof Post requires a paid processable order.';
        }

        if (! $inventory->hasSaleProofForOrder($order)) {
            return 'Sales Proof Post requires matching inventory sale proof.';
        }

        return null;
    }

    private function salesProofShoppingButtonUrl(Order $order): string
    {
        $productId = $order->orderItems->first()?->product_id;

        return $productId
            ? app(StorefrontUrl::class)->telegramProductStartUrlForId((int) $productId)
            : (string) (config('storefront.links.telegram_bot_url') ?: 'https://t.me/lhiestore_bot?start=landing');
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

    private function firstReadyReceiptEffect(string $groupKey): ?TelegramOutboundEffect
    {
        return TelegramOutboundEffect::query()
            ->where('effect_group_key', $groupKey)
            ->whereIn('state', [
                TelegramOutboundEffectState::Pending->value,
                TelegramOutboundEffectState::Failed->value,
            ])
            ->orderBy('sequence')
            ->get()
            ->first(fn (TelegramOutboundEffect $candidate): bool => $this->receiptEffectIsOrderedReady($candidate)
                && $this->isClaimable($candidate, now()));
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

    private function receiptEffectIsOrderedReady(TelegramOutboundEffect $effect): bool
    {
        if ($effect->effect_type !== self::TYPE_RECEIPT_CREDENTIALS
            || $effect->effect_group_key === null
            || (int) $effect->sequence <= 1) {
            return true;
        }

        return ! TelegramOutboundEffect::query()
            ->where('effect_group_key', $effect->effect_group_key)
            ->where('sequence', '<', $effect->sequence)
            ->where('state', '<>', TelegramOutboundEffectState::Sent->value)
            ->exists();
    }

    private static function receiptEffectKeyForGroup(string $groupKey, int $sequence): string
    {
        return $groupKey.':unit:'.$sequence;
    }

    private function isExplicitReceiptEffect(TelegramOutboundEffect $effect): bool
    {
        $metadata = is_array($effect->outcome_metadata) ? $effect->outcome_metadata : [];

        return $effect->effect_type === self::TYPE_RECEIPT_CREDENTIALS
            && ($metadata['delivery_context'] ?? null) === 'explicit_resend';
    }

    private function dispatchReceiptCleanup(int|string $orderId): void
    {
        DeleteBuyerCheckoutMessages::dispatch((string) $orderId, now()->toISOString())
            ->onQueue('notifications')
            ->afterCommit();
    }

    private function paymentForCheckoutEffect(Order $order, TelegramOutboundEffect $effect): ?Payment
    {
        return match ($effect->effect_type) {
            self::TYPE_MANUAL_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::MANUAL)
                ->where('method', 'QR_MANUAL')
                ->latest('created_at')
                ->first(),
            self::TYPE_KLIKQRIS_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::KLIKQRIS)
                ->latest('created_at')
                ->first(),
            self::TYPE_PAKASIR_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::PAKASIR)
                ->latest('created_at')
                ->first(),
            self::TYPE_BINANCE_CHECKOUT => $order->payments()
                ->where('provider', PaymentProvider::MANUAL)
                ->where('method', 'BINANCE_PAY_ID')
                ->latest('created_at')
                ->first(),
            default => null,
        };
    }

    private function sendCheckoutInstructions(
        TelegramOutboundEffect $effect,
        TelegramCheckoutIntents $checkoutIntents,
        Order $order,
        Payment $payment,
    ): array {
        return match ($effect->effect_type) {
            self::TYPE_MANUAL_CHECKOUT => $checkoutIntents->sendPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
            ),
            self::TYPE_KLIKQRIS_CHECKOUT => $checkoutIntents->sendKlikQrisPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
            ),
            self::TYPE_PAKASIR_CHECKOUT => $checkoutIntents->sendPakasirPaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
            ),
            self::TYPE_BINANCE_CHECKOUT => $checkoutIntents->sendBinancePaymentInstructions(
                (string) $effect->telegram_chat_id,
                $order,
                $payment,
                $order->customer ?? throw new RuntimeException('Checkout customer is missing.'),
            ),
            default => throw new RuntimeException('Unsupported Telegram checkout effect type.'),
        };
    }

    private function timingStep(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            self::TYPE_MANUAL_CHECKOUT => 'manual_payment_instructions',
            self::TYPE_KLIKQRIS_CHECKOUT => 'klikqris_payment_instructions',
            self::TYPE_PAKASIR_CHECKOUT => 'pakasir_payment_instructions',
            self::TYPE_BINANCE_CHECKOUT => 'binance_payment_instructions',
            default => 'checkout_payment_instructions',
        };
    }

    private function timingPaymentMode(TelegramOutboundEffect $effect): string
    {
        return match ($effect->effect_type) {
            self::TYPE_MANUAL_CHECKOUT => PaymentProvider::MANUAL,
            self::TYPE_KLIKQRIS_CHECKOUT => PaymentProvider::KLIKQRIS,
            self::TYPE_PAKASIR_CHECKOUT => PaymentProvider::PAKASIR,
            self::TYPE_BINANCE_CHECKOUT => 'binance',
            default => 'unknown',
        };
    }

    public function finalizeSent(string $effectId, string $claimToken, string $messageId): bool
    {
        return DB::transaction(function () use ($effectId, $claimToken, $messageId): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Sent->value,
                'lease_until' => null,
                'claim_token' => null,
                'telegram_message_id' => $messageId,
                'sent_at' => $now,
                'unknown_at' => null,
                'failed_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => null,
                'last_error' => null,
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['telegram_response' => 'ok']),
            ])->save();

            $this->markLegacySent($effect, $messageId, $now);

            return true;
        });
    }

    public function finalizeFailed(
        string $effectId,
        string $claimToken,
        Throwable $exception,
        ?int $retryAfterSeconds = null,
    ): bool {
        return DB::transaction(function () use ($effectId, $claimToken, $exception, $retryAfterSeconds): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $failureCode = $this->failureCode($exception);
            $isBroadcast = $effect->effect_type === self::TYPE_ADMIN_BROADCAST;
            $attemptsExhausted = $isBroadcast && (int) $effect->attempts >= self::ADMIN_BROADCAST_MAX_ATTEMPTS;
            $terminal = in_array($failureCode, self::NON_RETRYABLE_FAILURE_CODES, true) || $attemptsExhausted;
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Failed->value,
                'lease_until' => null,
                'claim_token' => null,
                'sent_at' => null,
                'failed_at' => $now,
                'unknown_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => $terminal
                    ? null
                    : ($effect->effect_type === self::TYPE_SALES_PROOF
                        ? ((int) $effect->attempts < self::SALES_PROOF_MAX_ATTEMPTS ? $now : null)
                        : $now->copy()->addSeconds($retryAfterSeconds ?? $this->retryDelaySeconds($effect->attempts))),
                'last_error_code' => $attemptsExhausted ? 'broadcast_recipient_attempts_exhausted' : $failureCode,
                'last_error' => 'Telegram delivery failed before completion.',
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
            ])->save();

            $this->clearLegacyClaim($effect);

            return true;
        });
    }

    private function finalizeNotDeliverable(string $effectId, string $claimToken, string $reason, string $message = 'Telegram checkout instruction was no longer eligible for delivery.'): bool
    {
        return DB::transaction(function () use ($effectId, $claimToken, $reason, $message): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Failed->value,
                'lease_until' => null,
                'claim_token' => null,
                'sent_at' => null,
                'failed_at' => $now,
                'unknown_at' => null,
                'manual_review_at' => null,
                'next_attempt_at' => null,
                'last_error_code' => $reason,
                'last_error' => $message,
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['reason' => $reason]),
            ])->save();

            $this->clearLegacyClaim($effect);

            return true;
        });
    }

    public function finalizeUnknown(string $effectId, string $claimToken, Throwable $exception): bool
    {
        return DB::transaction(function () use ($effectId, $claimToken, $exception): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if (! $this->claimMatches($effect, $claimToken)) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::Unknown->value,
                'lease_until' => null,
                'claim_token' => null,
                'next_attempt_at' => null,
                'sent_at' => null,
                'failed_at' => null,
                'unknown_at' => $now,
                'manual_review_at' => null,
                'last_error_code' => 'telegram_transport_ambiguous',
                'last_error' => 'Telegram delivery outcome is unknown and requires review.',
                'outcome_metadata' => $this->mergeOutcomeMetadata($effect, $this->safeExceptionMetadata($exception)),
            ])->save();

            $this->markSalesProofUnknown($effect, $now, 'Sales Proof delivery outcome is unknown and requires review.');

            // Keep the legacy claim as a compatibility projection. The
            // effect ledger is authoritative and prevents the old recovery
            // branch from blindly sending this effect again.

            return true;
        });
    }

    public function recoverExpiredLeases(?CarbonInterface $before = null): int
    {
        $before ??= now();

        return DB::transaction(function () use ($before): int {
            $effects = TelegramOutboundEffect::query()
                ->where('state', TelegramOutboundEffectState::Sending->value)
                ->whereNotNull('lease_until')
                ->where('lease_until', '<=', $before)
                ->lockForUpdate()
                ->get();
            $now = now();

            foreach ($effects as $effect) {
                $effect->forceFill([
                    'state' => TelegramOutboundEffectState::Unknown->value,
                    'lease_until' => null,
                    'claim_token' => null,
                    'next_attempt_at' => null,
                    'unknown_at' => $now,
                    'manual_review_at' => null,
                    'last_error_code' => 'lease_expired',
                    'last_error' => 'Worker lease expired before Telegram delivery was finalized.',
                    'outcome_metadata' => $this->mergeOutcomeMetadata($effect, ['reason' => 'worker_lease_expired']),
                ])->save();

                $this->markSalesProofUnknown($effect, $now, 'Worker lease expired before Sales Proof delivery was finalized.');
            }

            return $effects->count();
        });
    }

    public function promoteToManualReview(string $effectId): bool
    {
        return DB::transaction(function () use ($effectId): bool {
            $effect = TelegramOutboundEffect::query()->lockForUpdate()->find($effectId);
            if ($effect === null || $this->stateValue($effect) !== TelegramOutboundEffectState::Unknown->value) {
                return false;
            }

            $now = now();
            $effect->forceFill([
                'state' => TelegramOutboundEffectState::ManualReview->value,
                'manual_review_at' => $now,
                'updated_at' => $now,
            ])->save();
            $this->markSalesProofManualReview($effect, $now);

            return true;
        });
    }

    public function hasActiveLegacyClaim(TelegramOutboundEffect $effect): bool
    {
        if (! in_array($effect->effect_type, self::CHECKOUT_EFFECT_TYPES, true)) {
            return false;
        }

        $startedAt = Order::query()->whereKey($effect->source_id)->value('manual_checkout_customer_notification_started_at');

        return $startedAt !== null
            && Carbon::parse($startedAt)->gt(now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600))));
    }

    public function isAmbiguous(Throwable $exception): bool
    {
        if ($exception instanceof TelegramOutboundPreSendException) {
            return false;
        }

        for ($candidate = $exception; $candidate !== null; $candidate = $candidate->getPrevious()) {
            if ($candidate instanceof TelegramTransportException || $candidate instanceof ConnectionException) {
                return true;
            }

            if ($candidate instanceof RequestException) {
                $status = $candidate->response?->status();

                if ($status === null || $status === 408 || $status === 429 || $status >= 500) {
                    return true;
                }
            }

            $message = strtolower($candidate->getMessage());
            if (str_contains($message, 'request failed')
                || str_contains($message, 'connection')
                || str_contains($message, 'timed out')
                || str_contains($message, 'timeout')) {
                return true;
            }
        }

        return false;
    }

    private function isReviewable(TelegramOutboundEffect $effect): bool
    {
        return in_array($this->stateValue($effect), self::REVIEW_STATES, true);
    }

    public function isTerminalFailure(TelegramOutboundEffect $effect): bool
    {
        if ($this->stateValue($effect) !== TelegramOutboundEffectState::Failed->value) {
            return false;
        }

        return in_array($effect->last_error_code, self::NON_RETRYABLE_FAILURE_CODES, true)
            || ($effect->effect_type === self::TYPE_ADMIN_BROADCAST
                && (int) $effect->attempts >= self::ADMIN_BROADCAST_MAX_ATTEMPTS);
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
            } elseif (in_array($key, ['http_status', 'resolution_actor_id', 'receipt_chunk_index', 'receipt_chunk_count'], true)
                && ((is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value)))) {
                $safe[$key] = (int) $value;
            } elseif (in_array($key, ['attachment_present', 'late_evidence'], true) && is_bool($value)) {
                $safe[$key] = $value;
            } elseif ($key === 'exception' && is_string($value)
                && preg_match('/\\A[A-Za-z_][A-Za-z0-9_\\\\]*\\z/', $value) === 1) {
                $safe[$key] = class_basename($value);
            } elseif (in_array($key, ['reason', 'delivery_context', 'resolution', 'attachment_type', 'payment_method', 'proof_status'], true)
                && is_string($value)) {
                $safeValue = $this->safeErrorCode(is_string($value) ? $value : null);
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

    private function isClaimable(TelegramOutboundEffect $effect, CarbonInterface $at): bool
    {
        if (! in_array($effect->state, [TelegramOutboundEffectState::Pending, TelegramOutboundEffectState::Failed], true)
            || in_array($effect->last_error_code, self::NON_RETRYABLE_FAILURE_CODES, true)) {
            return false;
        }

        if ($effect->effect_type === self::TYPE_SALES_PROOF
            && (int) $effect->attempts >= self::SALES_PROOF_MAX_ATTEMPTS
            && (($effect->outcome_metadata['manual_retry_requested'] ?? false) !== true)) {
            return false;
        }

        if ($effect->effect_type === self::TYPE_ADMIN_BROADCAST
            && (int) $effect->attempts >= self::ADMIN_BROADCAST_MAX_ATTEMPTS) {
            return false;
        }

        return $effect->next_attempt_at === null || $effect->next_attempt_at->lte($at);
    }

    private function isDispatchable(TelegramOutboundEffect $effect): bool
    {
        if (! in_array($this->stateValue($effect), [
            TelegramOutboundEffectState::Pending->value,
            TelegramOutboundEffectState::Failed->value,
        ], true)) {
            return false;
        }

        if (in_array($effect->last_error_code, self::NON_RETRYABLE_FAILURE_CODES, true)) {
            return false;
        }

        if ($effect->effect_type === self::TYPE_SALES_PROOF
            && (int) $effect->attempts >= self::SALES_PROOF_MAX_ATTEMPTS) {
            return false;
        }

        if ($effect->effect_type === self::TYPE_ADMIN_BROADCAST
            && (int) $effect->attempts >= self::ADMIN_BROADCAST_MAX_ATTEMPTS) {
            return false;
        }

        return $effect->next_attempt_at === null || $effect->next_attempt_at->lte(now());
    }

    private function claimMatches(?TelegramOutboundEffect $effect, string $claimToken): bool
    {
        return $effect !== null
            && $effect->state === TelegramOutboundEffectState::Sending
            && $effect->claim_token !== null
            && hash_equals((string) $effect->claim_token, $claimToken);
    }

    private function markLegacyClaim(TelegramOutboundEffect $effect, CarbonInterface $at): void
    {
        if (in_array($effect->effect_type, self::SALES_PROOF_EFFECT_TYPES, true)) {
            SalesProofPost::query()
                ->where('order_id', $effect->source_id)
                ->update([
                    'status' => SalesProofPost::STATUS_SENDING,
                    'attempts' => (int) $effect->attempts,
                    'last_error' => null,
                    'updated_at' => now(),
                ]);

            return;
        }

        if (in_array($effect->effect_type, self::RECEIPT_EFFECT_TYPES, true)) {
            Order::query()->whereKey($effect->source_id)->update([
                'telegram_receipt_send_started_at' => $at,
                'updated_at' => now(),
            ]);

            return;
        }

        if (in_array($effect->effect_type, self::ADMIN_NOTIFICATION_EFFECT_TYPES, true)) {
            $startedColumn = match ($effect->effect_type) {
                self::TYPE_ADMIN_PENDING => 'admin_pending_order_notification_started_at',
                self::TYPE_ADMIN_PAID => 'admin_paid_order_notification_started_at',
                self::TYPE_ADMIN_EXPIRED => 'admin_expired_order_notification_started_at',
                default => null,
            };
            if ($startedColumn !== null) {
                Order::query()->whereKey($effect->source_id)->update([
                    $startedColumn => $at,
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        if ($effect->effect_type === self::TYPE_MANUAL_PAYMENT_PROOF) {
            Payment::query()->whereKey($effect->source_id)->update([
                'payment_proof_forwarding_started_at' => $at,
                'payment_proof_forwarding_token' => $effect->claim_token,
                'updated_at' => now(),
            ]);

            return;
        }

        if (in_array($effect->effect_type, self::ADMIN_BROADCAST_EFFECT_TYPES, true)) {
            TelegramBroadcastRecipient::query()
                ->whereKey($effect->source_id)
                ->update([
                    'send_started_at' => $at,
                    'send_claim_token' => $effect->claim_token,
                    'failed_at' => null,
                    'retry_at' => null,
                    'terminal_failed_at' => null,
                    'last_error' => null,
                    'attempts' => (int) $effect->attempts,
                    'updated_at' => now(),
                ]);
            $broadcastId = TelegramBroadcastRecipient::query()->whereKey($effect->source_id)->value('broadcast_id');
            if ($broadcastId !== null) {
                $this->syncAdminBroadcastProjection((int) $broadcastId);
            }

            return;
        }

        if (! in_array($effect->effect_type, self::CHECKOUT_EFFECT_TYPES, true)) {
            return;
        }

        Order::query()->whereKey($effect->source_id)->update([
            'manual_checkout_customer_notification_started_at' => $at,
            'updated_at' => now(),
        ]);
    }

    private function markLegacySent(TelegramOutboundEffect $effect, string $messageId, CarbonInterface $at): void
    {
        if (in_array($effect->effect_type, self::SALES_PROOF_EFFECT_TYPES, true)) {
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

            return;
        }

        if (in_array($effect->effect_type, self::RECEIPT_EFFECT_TYPES, true)) {
            if ($effect->effect_group_key !== self::receiptEffectGroupKey((string) $effect->source_id)) {
                return;
            }

            $hasUnsentUnit = TelegramOutboundEffect::query()
                ->where('effect_group_key', $effect->effect_group_key)
                ->where('state', '<>', TelegramOutboundEffectState::Sent->value)
                ->exists();
            if ($hasUnsentUnit) {
                return;
            }

            $updated = Order::query()
                ->whereKey($effect->source_id)
                ->whereNull('telegram_receipt_sent_at')
                ->update([
                    'telegram_receipt_sent_at' => $at,
                    'telegram_receipt_send_started_at' => null,
                    'updated_at' => now(),
                ]);

            if ($updated === 1) {
                $this->dispatchReceiptCleanup($effect->source_id);
            }

            return;
        }

        if ($effect->effect_type === self::TYPE_MANUAL_PAYMENT_PROOF) {
            Payment::query()
                ->whereKey($effect->source_id)
                ->whereNull('payment_proof_forwarded_at')
                ->update([
                    'payment_proof_admin_chat_id' => $effect->telegram_chat_id,
                    'payment_proof_forwarding_started_at' => null,
                    'payment_proof_forwarding_token' => null,
                    'payment_proof_forwarded_at' => $at,
                    'updated_at' => now(),
                ]);

            return;
        }

        if (in_array($effect->effect_type, self::ADMIN_BROADCAST_EFFECT_TYPES, true)) {
            TelegramBroadcastRecipient::query()
                ->whereKey($effect->source_id)
                ->whereNull('sent_at')
                ->update([
                    'sent_at' => $at,
                    'send_started_at' => null,
                    'send_claim_token' => null,
                    'failed_at' => null,
                    'retry_at' => null,
                    'terminal_failed_at' => null,
                    'last_error' => null,
                    'attempts' => (int) $effect->attempts,
                    'updated_at' => now(),
                ]);
            $broadcastId = TelegramBroadcastRecipient::query()->whereKey($effect->source_id)->value('broadcast_id');
            if ($broadcastId !== null) {
                $this->syncAdminBroadcastProjection((int) $broadcastId);
            }

            return;
        }

        if (in_array($effect->effect_type, self::ADMIN_NOTIFICATION_EFFECT_TYPES, true)) {
            $values = match ($effect->effect_type) {
                self::TYPE_ADMIN_PENDING => [
                    'admin_pending_order_notified_at' => $at,
                    'admin_pending_order_notification_started_at' => null,
                    'admin_pending_order_notification_chat_id' => $effect->telegram_chat_id,
                    'admin_pending_order_notification_message_id' => is_numeric($messageId) ? (int) $messageId : null,
                    'admin_pending_order_notification_deleted_at' => null,
                ],
                self::TYPE_ADMIN_PAID => [
                    'admin_paid_order_notified_at' => $at,
                    'admin_paid_order_notification_started_at' => null,
                ],
                self::TYPE_ADMIN_EXPIRED => [
                    'admin_expired_order_notified_at' => $at,
                    'admin_expired_order_notification_started_at' => null,
                ],
                default => [],
            };

            if ($values !== []) {
                Order::query()->whereKey($effect->source_id)->update($values + ['updated_at' => now()]);
            }

            return;
        }

        if (! in_array($effect->effect_type, self::CHECKOUT_EFFECT_TYPES, true)) {
            return;
        }

        $values = [
            'manual_checkout_customer_notified_at' => $at,
            'manual_checkout_customer_notification_started_at' => null,
            'manual_checkout_customer_notification_chat_id' => $effect->telegram_chat_id,
            'manual_checkout_customer_notification_message_id' => is_numeric($messageId) ? (int) $messageId : null,
            'manual_checkout_customer_notification_deleted_at' => null,
            'manual_checkout_customer_notification_deletion_started_at' => null,
            'manual_checkout_customer_notification_deletion_token' => null,
        ];

        Order::query()->whereKey($effect->source_id)->update($values + ['updated_at' => now()]);
    }

    private function clearLegacyClaim(TelegramOutboundEffect $effect): void
    {
        if (in_array($effect->effect_type, self::SALES_PROOF_EFFECT_TYPES, true)) {
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

            return;
        }

        if (in_array($effect->effect_type, self::RECEIPT_EFFECT_TYPES, true)) {
            Order::query()->whereKey($effect->source_id)->update([
                'telegram_receipt_send_started_at' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        if ($effect->effect_type === self::TYPE_MANUAL_PAYMENT_PROOF) {
            Payment::query()->whereKey($effect->source_id)->update([
                'payment_proof_forwarding_started_at' => null,
                'payment_proof_forwarding_token' => null,
                'updated_at' => now(),
            ]);

            return;
        }

        if (in_array($effect->effect_type, self::ADMIN_BROADCAST_EFFECT_TYPES, true)) {
            $terminal = $this->isTerminalFailure($effect);
            TelegramBroadcastRecipient::query()
                ->whereKey($effect->source_id)
                ->whereNull('sent_at')
                ->update([
                    'send_started_at' => null,
                    'send_claim_token' => null,
                    'failed_at' => $effect->failed_at ?? now(),
                    'retry_at' => $terminal ? null : $effect->next_attempt_at,
                    'terminal_failed_at' => $terminal ? ($effect->failed_at ?? now()) : null,
                    'last_error' => $effect->last_error ?: 'Telegram broadcast delivery failed before completion.',
                    'attempts' => $terminal ? self::ADMIN_BROADCAST_MAX_ATTEMPTS : (int) $effect->attempts,
                    'updated_at' => now(),
                ]);
            $broadcastId = TelegramBroadcastRecipient::query()->whereKey($effect->source_id)->value('broadcast_id');
            if ($broadcastId !== null) {
                $this->syncAdminBroadcastProjection((int) $broadcastId);
            }

            return;
        }

        if (in_array($effect->effect_type, self::ADMIN_NOTIFICATION_EFFECT_TYPES, true)) {
            $startedColumn = match ($effect->effect_type) {
                self::TYPE_ADMIN_PENDING => 'admin_pending_order_notification_started_at',
                self::TYPE_ADMIN_PAID => 'admin_paid_order_notification_started_at',
                self::TYPE_ADMIN_EXPIRED => 'admin_expired_order_notification_started_at',
                default => null,
            };
            if ($startedColumn !== null) {
                Order::query()->whereKey($effect->source_id)->update([
                    $startedColumn => null,
                    'updated_at' => now(),
                ]);
            }

            return;
        }

        if (! in_array($effect->effect_type, self::CHECKOUT_EFFECT_TYPES, true)) {
            return;
        }

        Order::query()->whereKey($effect->source_id)->update([
            'manual_checkout_customer_notification_started_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function syncAdminBroadcastProjection(int $broadcastId): void
    {
        $recipientIds = TelegramBroadcastRecipient::query()
            ->where('broadcast_id', $broadcastId)
            ->selectRaw('CAST(id AS TEXT)');
        $effects = TelegramOutboundEffect::query()
            ->where('source_type', TelegramBroadcastRecipient::class)
            ->where('effect_type', self::TYPE_ADMIN_BROADCAST)
            ->whereIn('source_id', $recipientIds);
        $recipientCount = TelegramBroadcastRecipient::query()->where('broadcast_id', $broadcastId)->count();
        $sentCount = (clone $effects)->where('state', TelegramOutboundEffectState::Sent->value)->count();
        $terminalFailedCount = (clone $effects)
            ->where('state', TelegramOutboundEffectState::Failed->value)
            ->where(function (Builder $terminal): void {
                $terminal
                    ->whereIn('last_error_code', [
                        'telegram_http_400',
                        'telegram_http_403',
                        'broadcast_recipient_attempts_exhausted',
                        'broadcast_recipient_terminal_failure',
                    ])
                    ->orWhere('attempts', '>=', self::ADMIN_BROADCAST_MAX_ATTEMPTS);
            })
            ->count();
        $pendingCount = max(0, $recipientCount - $sentCount - $terminalFailedCount);
        $complete = $recipientCount > 0 && $pendingCount === 0;
        $status = match (true) {
            $recipientCount === 0 => TelegramBroadcast::STATUS_COMPLETED,
            $complete && $sentCount === 0 => TelegramBroadcast::STATUS_FAILED,
            $complete && $terminalFailedCount > 0 => TelegramBroadcast::STATUS_PARTIALLY_FAILED,
            $complete => TelegramBroadcast::STATUS_COMPLETED,
            $sentCount > 0 || $terminalFailedCount > 0 => TelegramBroadcast::STATUS_IN_PROGRESS,
            default => TelegramBroadcast::STATUS_QUEUED,
        };

        TelegramBroadcast::query()->whereKey($broadcastId)->update([
            'status' => $status,
            'recipient_count' => $recipientCount,
            'sent_count' => $sentCount,
            'failed_count' => $terminalFailedCount,
            'terminal_failed_count' => $terminalFailedCount,
            'pending_count' => $pendingCount,
            'completed_at' => $complete || $recipientCount === 0 ? now() : null,
            'updated_at' => now(),
        ]);
    }

    private static function templateVersion(string $effectType): string
    {
        return match ($effectType) {
            self::TYPE_MANUAL_CHECKOUT => self::TEMPLATE_MANUAL_CHECKOUT,
            self::TYPE_KLIKQRIS_CHECKOUT => self::TEMPLATE_KLIKQRIS_CHECKOUT,
            self::TYPE_PAKASIR_CHECKOUT => self::TEMPLATE_PAKASIR_CHECKOUT,
            self::TYPE_BINANCE_CHECKOUT => self::TEMPLATE_BINANCE_CHECKOUT,
            self::TYPE_RECEIPT_CREDENTIALS => self::TEMPLATE_RECEIPT_CREDENTIALS,
            self::TYPE_ADMIN_PENDING => self::TEMPLATE_ADMIN_ORDER_PENDING,
            self::TYPE_ADMIN_PAID => self::TEMPLATE_ADMIN_ORDER_PAID,
            self::TYPE_ADMIN_EXPIRED => self::TEMPLATE_ADMIN_ORDER_EXPIRED,
            self::TYPE_ADMIN_BROADCAST => self::TEMPLATE_ADMIN_BROADCAST,
            self::TYPE_MANUAL_PAYMENT_PROOF => self::TEMPLATE_MANUAL_PAYMENT_PROOF,
            self::TYPE_SALES_PROOF => self::TEMPLATE_SALES_PROOF,
            default => 'checkout-payment-v1',
        };
    }

    private function retryDelaySeconds(int $attempts): int
    {
        return match (true) {
            $attempts <= 1 => 5,
            $attempts === 2 => 30,
            default => 120,
        };
    }

    private function failureCode(Throwable $exception): string
    {
        if ($exception instanceof TelegramOutboundPreSendException
            && str_contains(strtolower($exception->getMessage()), 'no longer renderable')) {
            return 'receipt_render_changed';
        }

        if ($exception instanceof RequestException && $exception->response !== null) {
            return 'telegram_http_'.(int) $exception->response->status();
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'message id')
            ? 'telegram_response_missing_message_id'
            : (str_contains($message, 'image') || str_contains($message, 'render')
                ? 'telegram_render_failed'
                : 'telegram_delivery_failed');
    }

    /** @return array{exception: string, http_status?: int} */
    private function safeExceptionMetadata(Throwable $exception): array
    {
        $metadata = ['exception' => class_basename($exception)];

        if ($exception instanceof RequestException && $exception->response !== null) {
            $metadata['http_status'] = (int) $exception->response->status();
        }

        return $metadata;
    }
}
