<?php

namespace App\Services\Admin;

use App\Enums\CredentialFormat;
use App\Enums\ExternalMoneyReceiptClaimOutcome;
use App\Enums\GuestOrderNotificationEvent;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\InsufficientStock;
use App\Exceptions\InvalidOrderTransition;
use App\Jobs\SendTelegramReceipt;
use App\Models\AuditLog;
use App\Models\BscPaymentTransferEvidence;
use App\Models\ExternalMoneyReceiptReservation;
use App\Models\ManualFulfillmentReview;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderRefund;
use App\Models\Payment;
use App\Models\PaymentBscDetail;
use App\Models\ProductStockItem;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Models\SupplierFulfillment;
use App\Models\SupplierManualHandoff;
use App\Models\SupplierPurchase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Credentials\StructuredCredentialPayload;
use App\Services\Credentials\UniqueCredentialEmailPolicy;
use App\Services\GuestOrderNotificationService;
use App\Services\InventoryLedger;
use App\Services\MoneyReceipts\ExternalMoneyReceiptReservations;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\OrderCredentialMailService;
use App\Services\OrderLifecycle;
use App\Services\ProductCredentialAllocator;
use App\Services\StoreBalance\StoreBalanceLedger;
use App\Services\Suppliers\SupplierFulfillmentService;
use App\Services\Telegram\AdminOrderNotificationDeliveryPipeline;
use App\Services\Telegram\SalesProofEligibility;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Services\TelegramStars\TelegramStarsRefunds;
use App\Services\UsdtBalance\CustomerUsdtBalanceLedger;
use App\Services\Warranty\RemainderRefundService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ManualFulfillmentResolutionService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryLedger $inventory,
        private readonly OrderLifecycle $orders,
        private readonly ProductCredentialAllocator $credentials,
        private readonly GuestOrderNotificationService $guestOrderNotifications,
        private readonly ExternalMoneyReceiptWriter $receipts,
        private readonly OrderCredentialMailService $credentialMails,
    ) {}

    public function resolve(ManualFulfillmentReview $review, User $actor, string $action, string $reason, ?string $refundReference = null, ?string $refundResult = null, ?string $ip = null): ManualFulfillmentReview
    {
        return DB::transaction(function () use ($review, $actor, $action, $reason, $refundReference, $refundResult, $ip) {
            $initial = ManualFulfillmentReview::query()->findOrFail($review->id);
            $initialPayment = Payment::query()->findOrFail($initial->payment_id);
            if ($action === 'refund' && in_array($initialPayment->provider, ['store_balance', 'usdt_balance'], true)) {
                $customer = Order::findOrFail($initial->order_id)->customer;
                $ledger = $initialPayment->provider === 'usdt_balance' ? app(CustomerUsdtBalanceLedger::class) : app(StoreBalanceLedger::class);
                $ledger->lockForTransaction($customer);
            }
            Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $initial->order_id)->orderBy('id')->lockForUpdate()->get();
            app(SupplierFulfillmentService::class)->lockForReview(Order::findOrFail($initial->order_id));
            $locked = ManualFulfillmentReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->state !== ManualFulfillmentReview::STATE_PENDING) {
                return $locked;
            }
            if ($action === 'fulfill') {
                $this->assertNoStarsRefund(Order::findOrFail($initial->order_id), $initialPayment->refresh());
            }

            return match ($action) {
                'fulfill' => $this->fulfill($locked, $actor, $reason, $ip),
                'refund' => $this->refund($locked, $actor, $reason, $refundReference, $refundResult, $ip),
                'reject' => $this->reject($locked, $actor, $reason, $ip),
                default => throw new InvalidOrderTransition('Unknown manual fulfillment resolution.'),
            };
        }, attempts: 5);
    }

    /** @param array<int, list<string|array<string, mixed>>> $credentials */
    public function provideLocalCredentials(ManualFulfillmentReview $review, User $actor, array $credentials, ?string $reason = null, ?string $ip = null): ManualFulfillmentReview
    {
        abort_unless($actor->role?->canManagePaymentSettings(), 403);
        $reason = $reason !== null && trim($reason) !== '' ? $reason : null;

        return DB::transaction(function () use ($review, $actor, $credentials, $reason, $ip): ManualFulfillmentReview {
            $initial = ManualFulfillmentReview::query()->findOrFail($review->id);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            app(SupplierFulfillmentService::class)->lockForReview($order);
            $locked = ManualFulfillmentReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($locked->reason === 'supplier_fulfillment'
                && AuditLog::query()->where('subject_type', ManualFulfillmentReview::class)->where('subject_id', (string) $locked->id)
                    ->where('action', 'manual_fulfillment_review.local_credentials_provided')->exists()) {
                return $locked;
            }
            if (! in_array($locked->reason, [ManualFulfillmentReview::REASON_UNIQUE_EMAIL_UNAVAILABLE, ManualFulfillmentReview::REASON_MANUAL_UNLIMITED], true)) {
                throw new InvalidOrderTransition('This review does not accept local credentials.');
            }
            if ($locked->state === ManualFulfillmentReview::STATE_FULFILLED) {
                return $locked;
            }
            $payment = Payment::query()->findOrFail($locked->payment_id);
            $this->assertNoStarsRefund($order, $payment);
            if ($locked->state !== ManualFulfillmentReview::STATE_PENDING
                || $order->payment_status !== PaymentStatus::Paid
                || $payment->status !== PaymentStatus::Paid
                || $order->order_status !== OrderStatus::Processing
                || OrderRefund::query()->where('order_id', $order->id)->exists()) {
                throw new InvalidOrderTransition('Only an active paid local credential review can accept accounts.');
            }
            if ($reason !== null && mb_strlen($reason) > 2000) {
                throw ValidationException::withMessages(['reason' => 'Catatan resolusi maksimal 2000 karakter.']);
            }

            $items = $order->orderItems()->orderBy('product_id')->orderBy('id')->get();
            $missing = $items->mapWithKeys(function (OrderItem $item): array {
                $allocated = $item->stockItems()->whereNull('supplier_purchase_id')->whereNull('retired_at')->count();

                return [$item->id => max(0, $item->localQuantity() - $allocated)];
            })->filter(fn (int $quantity): bool => $quantity > 0);
            if ($missing->isEmpty() || count($credentials) !== $missing->count()
                || array_diff(array_keys($credentials), $missing->keys()->all()) !== []) {
                throw ValidationException::withMessages(['credentials' => 'Kirim seluruh unit lokal yang masih kurang, tanpa item tambahan.']);
            }

            $candidates = [];
            $byProduct = [];
            foreach ($items as $item) {
                if (! $missing->has($item->id)) {
                    continue;
                }
                $input = $credentials[$item->id] ?? null;
                if (! is_array($input) || ! array_is_list($input) || count($input) !== $missing[$item->id]) {
                    throw ValidationException::withMessages(['credentials' => $item->product_name.': jumlah akun harus '.$missing[$item->id].' unit.']);
                }
                foreach ($input as $account) {
                    $candidate = $this->localCredentialCandidate($item, $account);
                    $candidates[$item->id][] = $candidate;
                    $byProduct[$item->product_id][] = $candidate;
                }
            }
            $policy = app(UniqueCredentialEmailPolicy::class);
            foreach ($items as $item) {
                if (isset($candidates[$item->id])) {
                    $policy->assertCanAssign($item, $byProduct[$item->product_id]);
                }
            }
            foreach ($candidates as $itemId => $accounts) {
                foreach ($accounts as $account) {
                    $account->order_item_id = $itemId;
                    $account->save();
                }
            }

            $originalReason = $locked->reason;
            $resolved = $this->markResolved($locked, ManualFulfillmentReview::STATE_FULFILLED, $actor, $reason);
            if (SupplierFulfillment::query()->where('order_id', $order->id)->where('state', 'review')->exists()) {
                $resolved->update([
                    'reason' => 'supplier_fulfillment',
                    'state' => ManualFulfillmentReview::STATE_PENDING,
                    'resolution_reason' => null,
                    'actor_user_id' => null,
                    'resolved_at' => null,
                ]);
                $this->audit->log('manual_fulfillment_review.supplier_review_resumed', $resolved, [
                    'state' => ManualFulfillmentReview::STATE_FULFILLED,
                    'reason' => $originalReason,
                    'resolution_reason' => $reason,
                ], ['state' => ManualFulfillmentReview::STATE_PENDING, 'reason' => 'supplier_fulfillment'], $actor, $ip);
            }
            $complete = $items->every(fn (OrderItem $item): bool => $item->stockItems()->whereNull('retired_at')->count() === $item->quantity)
                && ! app(SupplierFulfillmentService::class)->hasPending($order)
                && ! ManualFulfillmentReview::query()->where('order_id', $order->id)->open()->exists();
            if ($complete) {
                $order->update(['order_status' => OrderStatus::Completed]);
                $this->guestOrderNotifications->record($order, GuestOrderNotificationEvent::FulfillmentCompleted);
                $this->credentialMails->record($order);
                if ((bool) StoreChannelSetting::current()->sales_proof_enabled
                    && app(SalesProofEligibility::class)->rejectionReason($order) === null) {
                    $post = SalesProofPost::query()->firstOrCreate(['order_id' => $order->id], ['status' => SalesProofPost::STATUS_PENDING]);
                    $effect = app(TelegramOutboundEffectService::class)->recordSalesProofPost($order, $post);
                    if ($effect !== null) {
                        app(TelegramOutboundEffectDispatcher::class)->dispatch($effect);
                    }
                }
                app(AdminOrderNotificationDeliveryPipeline::class)->wakePaidOrder((string) $order->id);
                SendTelegramReceipt::dispatch(
                    (string) $order->getKey(),
                    false,
                    now()->toISOString(),
                    null,
                    SendTelegramReceipt::ENQUEUE_FIRST,
                    SendTelegramReceipt::REASON_MANUAL_FULFILLMENT,
                )->onQueue(TelegramOutboundEffectService::RECEIPT_QUEUE);
            }
            $this->audit->log('manual_fulfillment_review.local_credentials_provided', $locked, ['state' => ManualFulfillmentReview::STATE_PENDING], [
                'state' => $resolved->state,
                'reason' => $reason,
                'units' => array_sum($missing->all()),
                'order_status' => $order->order_status->value,
            ], $actor, $ip);

            return $resolved;
        }, attempts: 5);
    }

    private function localCredentialCandidate(OrderItem $item, mixed $account): ProductStockItem
    {
        if (is_string($account) && trim($account) !== '' && strlen($account) <= 16384) {
            return new ProductStockItem(['product_id' => $item->product_id, 'credential_format' => CredentialFormat::Legacy, 'secret' => trim($account)]);
        }
        if (is_array($account)) {
            try {
                if (isset($account['tutorial_override']) && $account['tutorial_override'] !== '') {
                    throw new InvalidArgumentException('Tutorial akun sekarang mengikuti panduan Product.');
                }
                $payload = StructuredCredentialPayload::fromArray($account + ['version' => StructuredCredentialPayload::VERSION, 'access_method' => 'none']);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['credentials' => $exception->getMessage()]);
            }

            return new ProductStockItem(['product_id' => $item->product_id, 'credential_format' => CredentialFormat::StructuredAccount, 'credential_payload' => $payload->toArray()]);
        }

        throw ValidationException::withMessages(['credentials' => 'Data akun tidak valid.']);
    }

    /**
     * Records customer delivery performed outside the Store Bot. The original
     * supplier purchase remains unresolved: its hold stays in place until
     * independently proven as either a debit or no-debit.
     */
    public function recordExternalHandoff(ManualFulfillmentReview $review, User $actor, int $fulfillmentId, int $quantity, string $reason, ?string $ip = null, ?string $idempotencyKey = null): ManualFulfillmentReview
    {
        if ($quantity < 1) {
            throw new InvalidOrderTransition('Jumlah penyerahan harus lebih dari nol.');
        }
        $idempotencyKey ??= (string) Str::uuid();
        if (! Str::isUuid($idempotencyKey) || trim($reason) === '') {
            throw new InvalidOrderTransition('Kunci penyerahan tidak valid.');
        }
        $reason = trim($reason);

        return DB::transaction(function () use ($review, $actor, $fulfillmentId, $quantity, $reason, $ip, $idempotencyKey): ManualFulfillmentReview {
            $initial = ManualFulfillmentReview::query()->findOrFail($review->id);
            $order = Order::query()->lockForUpdate()->findOrFail($initial->order_id);
            Payment::query()->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get();
            app(SupplierFulfillmentService::class)->lockForReview($order);
            $locked = ManualFulfillmentReview::query()->lockForUpdate()->findOrFail($initial->id);
            if ($locked->reason !== 'supplier_fulfillment') {
                throw new InvalidOrderTransition('Handoff hanya tersedia untuk review fulfillment supplier yang masih terbuka.');
            }
            if ($order->payment_status !== PaymentStatus::Paid || ! in_array($order->order_status, [OrderStatus::Processing, OrderStatus::Completed], true)) {
                throw new InvalidOrderTransition('Handoff hanya tersedia untuk pesanan berbayar yang aktif.');
            }

            $fulfillment = SupplierFulfillment::query()
                ->whereKey($fulfillmentId)
                ->where('order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = SupplierManualHandoff::query()
                ->where('supplier_fulfillment_id', $fulfillment->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                if ($existing->actor_user_id !== $actor->id || $existing->quantity !== $quantity || $existing->note !== $reason) {
                    throw new InvalidOrderTransition('Kunci penyerahan sudah digunakan untuk data berbeda.');
                }

                return $locked->fresh();
            }
            if ($locked->state !== ManualFulfillmentReview::STATE_PENDING) {
                throw new InvalidOrderTransition('Review ini sudah diselesaikan.');
            }
            if ($fulfillment->state !== 'review') {
                throw new InvalidOrderTransition('Fulfillment ini tidak menunggu handoff manual.');
            }
            if (SupplierPurchase::query()->where('supplier_fulfillment_id', $fulfillment->id)
                ->where('lease_expires_at', '>', now())->exists()) {
                throw new InvalidOrderTransition('Purchase masih berjalan; tunggu hasilnya sebelum mencatat handoff.');
            }

            $alreadyHandedOff = SupplierManualHandoff::query()
                ->where('supplier_fulfillment_id', $fulfillment->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->sum('quantity');
            $allocated = ProductStockItem::query()->where('order_item_id', $fulfillment->order_item_id)
                ->whereHas('supplierPurchase', fn ($query) => $query->where('supplier_fulfillment_id', $fulfillment->id)->whereNull('warranty_claim_id'))->count();
            $remainingObligation = max(0, $fulfillment->quantity - $allocated - (int) $fulfillment->refunded_quantity);
            if ($alreadyHandedOff + $quantity > $remainingObligation) {
                throw new InvalidOrderTransition('Jumlah handoff melebihi kewajiban fulfillment.');
            }

            $handoff = SupplierManualHandoff::query()->create([
                'supplier_fulfillment_id' => $fulfillment->id,
                'actor_user_id' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'quantity' => $quantity,
                'note' => $reason,
                'handed_off_at' => now(),
            ]);
            $this->audit->log('supplier_fulfillment.external_handoff_recorded', $handoff, null, [
                'fulfillment_id' => $fulfillment->id,
                'quantity' => $quantity,
                'note' => $reason,
            ], $actor, $ip);

            if ($alreadyHandedOff + $quantity < $remainingObligation) {
                return $locked->fresh();
            }

            // Do not release the supplier hold or alter the unresolved purchase.
            // recoverOrder() recognizes delivered fulfillments and settles only
            // the original economic evidence without materializing credentials.
            $fulfillment->update([
                'state' => 'delivered',
                'delivered_at' => now(),
                'next_attempt_at' => null,
                'last_error' => 'external_handoff_completed',
            ]);

            if (SupplierFulfillment::query()->where('order_id', $order->id)->where('state', '!=', 'delivered')->exists()) {
                return $locked->fresh();
            }

            $order->update(['order_status' => OrderStatus::Completed]);
            $this->guestOrderNotifications->record($order, GuestOrderNotificationEvent::FulfillmentCompleted);
            $this->credentialMails->record($order);
            $resolved = $this->markResolved($locked, ManualFulfillmentReview::STATE_FULFILLED, $actor, $reason);
            $this->audit->log('manual_fulfillment_review.external_handoff_completed', $locked, ['state' => ManualFulfillmentReview::STATE_PENDING], [
                'fulfillment_id' => $fulfillment->id,
                'quantity' => $quantity,
                'order_status' => OrderStatus::Completed->value,
            ], $actor, $ip);

            return $resolved;
        }, attempts: 5);
    }

    private function fulfill(ManualFulfillmentReview $review, User $actor, string $reason, ?string $ip): ManualFulfillmentReview
    {
        if ($review->reason === ManualFulfillmentReview::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE) {
            throw new InvalidOrderTransition('Superseded automatic payment money must be reviewed separately; it cannot fulfill the Order again.');
        }
        if ($review->reason === 'supplier_fulfillment') {
            $fulfillments = SupplierFulfillment::query()->where('order_id', $review->order_id)->where('state', 'review')->pluck('id');
            foreach ($fulfillments as $id) {
                app(SupplierFulfillmentService::class)->retryAfterTopup($id);
            }

            return $review->fresh();
        }
        $order = Order::query()->lockForUpdate()->findOrFail($review->order_id);
        if (in_array($review->reason, [ManualFulfillmentReview::REASON_UNIQUE_EMAIL_UNAVAILABLE, ManualFulfillmentReview::REASON_MANUAL_UNLIMITED], true)
            || $order->orderItems()->where('deferred_local_quantity', '>', 0)->exists()) {
            throw new InvalidOrderTransition('Provide all missing local credentials through the account submission form.');
        }
        $payment = Payment::query()->lockForUpdate()->findOrFail($review->payment_id);
        $isPendingBscReview = $payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && strtolower((string) $payment->provider) === 'manual'
            && strtoupper((string) $payment->method) === 'USDT_BEP20';

        if ($isPendingBscReview) {
            if ($this->claimPendingBscReceipt($payment)->isHeld()) {
                throw new InvalidOrderTransition('The BSC payment receipt is held for review and cannot be completed.');
            }

            $completion = $this->orders->applyManualCompletion($order);
            if (! $completion->newlyApplied) {
                throw new InvalidOrderTransition('The BSC payment review could not be completed from its current state.');
            }
        } else {
            if ($payment->status !== PaymentStatus::Paid || $order->payment_status !== PaymentStatus::Paid) {
                throw new InvalidOrderTransition('Only paid manual fulfillment reviews can be fulfilled.');
            }

            [$items, $movements, $products] = $this->inventory->orderInventoryState($order);
            if ($items->contains(fn ($item) => $item->supplier_quantity > 0)) {
                throw new InvalidOrderTransition('This order needs explicit manual delivery through the fulfillment review.');
            }
            $this->inventory->recordLatePaymentSales($order, $items, $products, $movements);
            foreach ($items as $item) {
                $product = $products[$item->product_id] ?? throw new InsufficientStock('Ordered product is no longer available.');
                $this->credentials->allocate($item, $product, $item->quantity);
            }
            $order->update(['order_status' => OrderStatus::Completed]);
            $this->guestOrderNotifications->record($order, GuestOrderNotificationEvent::FulfillmentCompleted);
            $this->credentialMails->record($order);
        }

        $order->refresh();
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_FULFILLED, $actor, $reason);
        $this->audit->log('manual_fulfillment_review.fulfilled', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'order_status' => OrderStatus::Completed->value], $actor, $ip);
        if (! $isPendingBscReview) {
            SendTelegramReceipt::dispatch(
                (string) $order->getKey(),
                false,
                now()->toISOString(),
                null,
                SendTelegramReceipt::ENQUEUE_FIRST,
                SendTelegramReceipt::REASON_MANUAL_FULFILLMENT,
            )->onQueue(TelegramOutboundEffectService::RECEIPT_QUEUE);
        }

        return $resolved;
    }

    /**
     * Owner-approved BSC evidence claims the chain receipt from the registry;
     * legacy reviews without persisted evidence fall back to a per-payment
     * owner attestation so no approval can move money without a claim.
     */
    private function claimPendingBscReceipt(Payment $payment): ExternalMoneyReceiptClaimOutcome
    {
        $evidence = BscPaymentTransferEvidence::query()
            ->where('payment_id', $payment->getKey())
            ->orderByDesc('id')
            ->first();

        if ($evidence === null) {
            return $this->receipts->claimOwnerAttestation($payment);
        }

        if ($evidence->log_index === null) {
            $this->receipts->reservePayment(
                $payment,
                ExternalMoneyReceiptReservation::REASON_MISSING_CHAIN_IDENTITY,
                ['tx_hash' => (string) $evidence->tx_hash],
            );

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        $detail = PaymentBscDetail::query()->whereKey($payment->getKey())->first();

        try {
            $receipt = $this->receipts->receiptForBscPayment(
                $payment,
                (int) ($detail?->chain_id ?: config('services.bsc.chain_id', 56)),
                (string) $evidence->tx_hash,
                (int) $evidence->log_index,
                (string) $evidence->receiver_address,
                (string) $evidence->amount_units,
                $detail?->token_decimals === null ? null : (int) $detail->token_decimals,
            );
        } catch (InvalidArgumentException $exception) {
            $this->receipts->reservePayment(
                $payment,
                ExternalMoneyReceiptReservation::REASON_UNNORMALIZABLE_IDENTITY,
                ['identity_error' => $exception->getMessage()],
            );

            return ExternalMoneyReceiptClaimOutcome::Review;
        }

        return $this->receipts->claim(
            $receipt,
            ExternalMoneyReceiptReservations::SOURCE_RUNTIME,
            ExternalMoneyReceiptReservation::LEGACY_TYPE_PAYMENT,
            (string) $payment->getKey(),
        );
    }

    private function assertNoStarsRefund(Order $order, Payment $payment): void
    {
        if ($payment->isTelegramStars() && app(TelegramStarsRefunds::class)->blocksManualDelivery($order, $payment)) {
            throw new InvalidOrderTransition('Telegram Stars refund or missing receipt requires review before fulfillment.');
        }
    }

    private function refund(ManualFulfillmentReview $review, User $actor, string $reason, ?string $reference, ?string $result, ?string $ip): ManualFulfillmentReview
    {
        $order = Order::findOrFail($review->order_id);
        if ($review->reason !== ManualFulfillmentReview::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE) {
            app(SupplierFulfillmentService::class)->assertRefundable($order);
        }
        $payment = Payment::query()->findOrFail($review->payment_id);
        if ($payment->isTelegramStars()) {
            throw new InvalidOrderTransition('Telegram Stars refunds require the original Telegram charge.');
        }
        if (in_array($payment->provider, ['store_balance', 'usdt_balance'], true)) {
            $this->orders->applyPaymentRefunded($payment);

            return $this->markResolved($review, ManualFulfillmentReview::STATE_REFUNDED, $actor, $reason, null, 'success');
        }
        if (! filled($reference) || ! in_array($result, ['success', 'failure', 'uncertain'], true)) {
            throw new InvalidOrderTransition('A provider reference and explicit refund result are required.');
        }
        if ($result === 'failure') {
            $this->audit->log('manual_fulfillment_review.refund_failed', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'provider_reference' => $reference, 'provider_result' => $result], $actor, $ip);
            throw new InvalidOrderTransition('The provider reported the refund failed; the review remains pending.');
        }
        if ($result === 'uncertain') {
            return $this->markRefundUncertain($review, $actor, $reason, $reference, $ip);
        }
        if ($review->reason === ManualFulfillmentReview::REASON_PAYMENT_AFTER_MANUAL_ALTERNATIVE) {
            $this->orders->applyPaymentRefunded($payment);
            $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_REFUNDED, $actor, $reason, $reference, $result);
            $this->audit->log('manual_alternative.original_payment_refunded', $review, null, ['provider_reference' => $reference, 'amount' => $review->amount], $actor, $ip);

            return $resolved;
        }

        $remainder = app(RemainderRefundService::class)->registerConfirmedExternal($actor, $order, $payment, $reference);
        $this->orders->applyPaymentRefunded($payment);
        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_REFUNDED, $actor, $reason, $reference, $result);
        $this->audit->log('manual_fulfillment_review.refunded', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'amount' => $review->amount, 'currency' => $review->currency, 'provider_reference' => $reference, 'provider_result' => $result, 'remainder_refund_id' => $remainder?->id], $actor, $ip);

        return $resolved;
    }

    private function reject(ManualFulfillmentReview $review, User $actor, string $reason, ?string $ip): ManualFulfillmentReview
    {
        $order = Order::query()->lockForUpdate()->findOrFail($review->order_id);
        $payment = Payment::query()->lockForUpdate()->findOrFail($review->payment_id);
        if ($payment->status === PaymentStatus::Pending
            && $order->order_status === OrderStatus::PendingPayment
            && strtolower((string) $payment->provider) === 'manual'
            && strtoupper((string) $payment->method) === 'USDT_BEP20') {
            $this->orders->applyPendingCancellation($order);
        }

        $resolved = $this->markResolved($review, ManualFulfillmentReview::STATE_REJECTED, $actor, $reason);
        $this->audit->log('manual_fulfillment_review.rejected', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason], $actor, $ip);

        return $resolved;
    }

    private function markRefundUncertain(ManualFulfillmentReview $review, User $actor, string $reason, string $reference, ?string $ip): ManualFulfillmentReview
    {
        $review->update(['state' => ManualFulfillmentReview::STATE_REFUND_UNCERTAIN, 'resolution_reason' => $reason, 'refund_provider_reference' => $reference, 'refund_provider_result' => 'uncertain', 'refund_requested_at' => now(), 'actor_user_id' => $actor->id, 'resolved_at' => now()]);
        $this->audit->log('manual_fulfillment_review.refund_uncertain', $review, ['state' => ManualFulfillmentReview::STATE_PENDING], ['reason' => $reason, 'provider_reference' => $reference], $actor, $ip);

        return $review->fresh();
    }

    private function markResolved(ManualFulfillmentReview $review, string $state, User $actor, ?string $reason, ?string $refundReference = null, ?string $refundResult = null): ManualFulfillmentReview
    {
        $review->update(['state' => $state, 'resolution_reason' => $reason, 'refund_provider_reference' => $refundReference, 'refund_provider_result' => $refundResult, 'refund_requested_at' => $refundReference ? now() : null, 'actor_user_id' => $actor->id, 'resolved_at' => now()]);

        return $review->fresh();
    }
}
