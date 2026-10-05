<?php

namespace App\Services\StoreBalance;

use App\Enums\ExternalMoneyReceiptTargetType;
use App\Enums\UserRole;
use App\Exceptions\BalanceAdjustmentRejected;
use App\Models\BalanceAdjustmentRequest;
use App\Models\Customer;
use App\Models\OrderRefund;
use App\Models\StoreBalanceEntry;
use App\Models\StoreBalanceTopUp;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\MoneyReceipts\ExternalMoneyReceiptReservations;
use App\Services\MoneyReceipts\ExternalMoneyReceiptWriter;
use App\Services\MoneyReceipts\MoneyReceiptTarget;
use App\Services\MoneyReceipts\VerifiedMoneyReceipt;
use App\Services\Telegram\TelegramLocale;
use App\Services\Telegram\TelegramOutboundEffectService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class BalanceAdjustmentService
{
    public function __construct(
        private readonly StoreBalanceLedger $ledger,
        private readonly BalanceAdjustmentPasswordConfirmation $passwords,
        private readonly AuditLogger $audit,
        private readonly TelegramOutboundEffectService $outbound,
        private readonly ExternalMoneyReceiptWriter $receipts,
        private readonly StoreBalanceOwnerCompensationService $compensation,
        private readonly BalanceAdjustmentRefundRecovery $refunds,
        private readonly TelegramLocale $locale,
    ) {}

    /**
     * Resolve a Customer for the Owner adjustment screen without coercing the
     * Telegram ID through a floating-point or integer form.
     */
    public function findRegisteredCustomerByTelegramId(User $actor, mixed $telegramId): ?Customer
    {
        $this->owner($actor);

        return $this->registeredCustomer($telegramId);
    }

    public function request(User $actor, mixed $telegramId, mixed $amount, string $reason,
        string $category = 'owner_attested', ?string $sourceId = null, ?VerifiedMoneyReceipt $receipt = null): BalanceAdjustmentRequest
    {
        $actor = $this->owner($actor);
        $amount = $this->exactAmount($amount);
        $customer = $this->registeredCustomer($telegramId);
        if ($customer === null) {
            throw new BalanceAdjustmentRejected('customer_not_registered');
        }
        $reason = trim($reason);
        if ($amount <= 0 || $reason === '' || mb_strlen($reason) > 500 || preg_match('/[\x00-\x1F\x7F]/u', $reason)) {
            throw new BalanceAdjustmentRejected('invalid_amount_or_reason');
        }
        if (! in_array($category, ['owner_attested', 'funded_transfer', 'promotional_grant', 'correction', 'top_up_recovery', 'refund_recovery'], true)
            || (! in_array($category, ['top_up_recovery', 'refund_recovery'], true) && $sourceId !== null)
            || ($category !== 'funded_transfer' && $receipt !== null)) {
            throw new BalanceAdjustmentRejected('invalid_source');
        }
        if ($category === 'top_up_recovery' && ($sourceId === null || preg_match('/^[1-9][0-9]{0,18}$/D', $sourceId) !== 1)) {
            throw new BalanceAdjustmentRejected('invalid_original_top_up');
        }
        if ($category === 'refund_recovery' && ($sourceId === null || ! Str::isUuid($sourceId))) {
            throw new BalanceAdjustmentRejected('invalid_original_refund');
        }
        $id = (string) Str::uuid();
        $receipt = $receipt === null ? null : $this->bindReceipt($receipt, $id, $customer->id, $amount);
        if ($category === 'funded_transfer' && $receipt === null) {
            throw new BalanceAdjustmentRejected('verified_receipt_required');
        }
        if ($category === 'top_up_recovery') {
            $topUp = StoreBalanceTopUp::find($sourceId);
            if ($topUp === null || (int) $topUp->customer_id !== (int) $customer->id || (int) $topUp->amount !== $amount
                || $topUp->currency !== 'IDR' || ! $topUp->isEligibleForOwnerCompensation()) {
                throw new BalanceAdjustmentRejected('invalid_original_top_up');
            }
        }
        if ($category === 'refund_recovery') {
            $refund = OrderRefund::find($sourceId);
            if ($refund === null || (int) $refund->customer_id !== (int) $customer->id || $refund->amount !== $amount
                || $refund->currency !== 'IDR' || $refund->state !== 'requested' || $refund->external_reference_hash !== null) {
                throw new BalanceAdjustmentRejected('invalid_original_refund');
            }
        }
        $attributes = [
            'id' => $id, 'customer_id' => $customer->id, 'telegram_id' => (string) $customer->telegram_id,
            'username' => $customer->username, 'owner_user_id' => $actor->id,
            'session_binding' => $this->sessionBinding(), 'credential_binding' => hash('sha256', $actor->password),
            'mode' => 'single_owner', 'currency' => 'IDR', 'amount' => $amount, 'reason' => $reason,
            'category' => $category, 'source_id' => $sourceId, 'receipt_fingerprint' => $receipt?->fingerprint(),
            'version' => 1, 'state' => 'pending', 'expires_at' => now()->addMinutes(5),
        ];
        $attributes['fingerprint'] = hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($attributes, $actor): BalanceAdjustmentRequest {
            $actor = $this->owner(User::query()->whereKey($actor->id)->sharedLock()->firstOrFail());
            if ($attributes['credential_binding'] !== hash('sha256', $actor->password)) {
                throw new BalanceAdjustmentRejected('credentials_changed');
            }
            $preview = new BalanceAdjustmentRequest;
            $preview->forceFill($attributes)->save();
            $this->audit->logWithResolvedContext('balance.adjustment.requested', $preview, new: $this->auditValues($preview), user: $actor);

            return $preview;
        }, 5);
    }

    public function confirm(User $actor, string $id, string $fingerprint, int $version, string $password,
        ?VerifiedMoneyReceipt $receipt = null): StoreBalanceMutationResult
    {
        $actor = $this->owner($actor);
        $identity = $this->boundRequest($actor, $id, $fingerprint, $version);
        $approval = $this->passwords->verify($actor, $password, $identity);
        $token = $approval->token;
        try {
            $result = DB::transaction(function () use ($actor, $identity, $fingerprint, $version, $token, $receipt, $approval): StoreBalanceMutationResult|BalanceAdjustmentRejected {
                // Recheck persisted authority and password rotation under a shared lock.
                $freshActor = User::query()->whereKey($actor->id)->sharedLock()->firstOrFail();
                $this->owner($freshActor);
                if ($freshActor->password !== $actor->password) {
                    throw new BalanceAdjustmentRejected('credentials_changed');
                }
                $this->passwords->consume($freshActor, $token);
                $customer = Customer::query()->whereKey($identity->customer_id)->lockForUpdate()->firstOrFail();
                $this->ledger->lockForTransaction($customer);
                $preview = $this->boundRequest($freshActor, $identity->id, $fingerprint, $version, true);
                if ((string) $customer->telegram_id !== $preview->telegram_id) {
                    throw new BalanceAdjustmentRejected('recipient_changed');
                }
                if ($preview->category === 'funded_transfer') {
                    if ($receipt === null) {
                        throw new BalanceAdjustmentRejected('verified_receipt_required');
                    }
                    $verified = $this->bindReceipt($receipt, $preview->id, $customer->id, $preview->amount);
                    if ($verified->fingerprint() !== $preview->receipt_fingerprint) {
                        throw new BalanceAdjustmentRejected('receipt_changed');
                    }
                } elseif ($receipt !== null) {
                    throw new BalanceAdjustmentRejected('unexpected_receipt');
                }
                if ($preview->state === 'applied') {
                    return StoreBalanceMutationResult::replayed(StoreBalanceEntry::findOrFail($preview->ledger_entry_id));
                }
                if ($preview->state !== 'pending' || now()->gte($preview->expires_at)) {
                    throw new BalanceAdjustmentRejected('preview_unavailable');
                }
                if (isset($verified) && $this->receipts->claim($verified, ExternalMoneyReceiptReservations::SOURCE_RUNTIME, 'balance_adjustment', $preview->id)->isHeld()) {
                    return new BalanceAdjustmentRejected('receipt_unavailable');
                }
                $preview->forceFill(['state' => 'approved'])->save();
                if ($preview->category === 'top_up_recovery') {
                    $topUp = StoreBalanceTopUp::query()->whereKey($preview->source_id)->lockForUpdate()->firstOrFail();
                    if ((int) $topUp->customer_id !== (int) $customer->id || (int) $topUp->amount !== $preview->amount || $topUp->currency !== 'IDR') {
                        throw new BalanceAdjustmentRejected('original_top_up_changed');
                    }
                    $recovery = $this->compensation->release($preview->source_id, $freshActor, $topUp->provider_reference ?: 'owner-adjustment:'.$preview->id, $preview->reason);
                    if (! $recovery->applied) {
                        throw new BalanceAdjustmentRejected('original_source_already_resolved');
                    }
                    $mutation = StoreBalanceMutationResult::applied(StoreBalanceEntry::findOrFail($recovery->compensation->ledger_entry_id));
                    if ($this->outbound->recordOwnerCompensationNotification($recovery->compensation, $customer) === null) {
                        throw new BalanceAdjustmentRejected('original_top_up_notification_unavailable');
                    }
                } elseif ($preview->category === 'refund_recovery') {
                    $mutation = $this->refunds->apply($customer, $preview, $approval);
                } else {
                    $mutation = $this->ledger->creditApprovedAdjustment($preview, $approval);
                }
                $preview->forceFill(['state' => 'applied', 'ledger_entry_id' => $mutation->entryId, 'resolved_at' => now()])->save();
                DB::table('balance_adjustment_accounting_events')->insert([
                    'request_id' => $preview->id, 'ledger_entry_id' => $mutation->entryId, 'currency' => 'IDR', 'amount' => $preview->amount,
                    'debit_account' => match ($preview->category) {
                        'funded_transfer' => 'verified_settlement_asset', 'promotional_grant' => 'promotional_expense',
                        'refund_recovery' => 'sale_refund', 'correction' => 'correction_suspense', default => 'owner_attested_adjustment_suspense',
                    },
                    'credit_account' => 'customer_idr_liability', 'source_fingerprint' => $preview->fingerprint, 'created_at' => now(),
                ]);
                $this->audit->logWithResolvedContext('balance.adjustment.applied', $preview, new: $this->auditValues($preview), user: $freshActor);
                if ($preview->category !== 'top_up_recovery') {
                    $this->outbound->recordBalanceAdjustmentNotice($preview->id, 'customer', $this->locale->translate($customer, 'balance.adjustment.credit', ['amount' => 'Rp '.number_format($preview->amount, 0, ',', '.')]), $customer);
                }
                $this->outbound->recordBalanceAdjustmentNotice($preview->id, 'owner', 'Adjustment IDR '.$preview->id.' diterapkan: Rp '.number_format($preview->amount, 0, ',', '.').'.');

                return $mutation;
            }, 5);
            if ($result instanceof BalanceAdjustmentRejected) {
                throw $result;
            }

            return $result;
        } finally {
            $this->passwords->release($actor, $token);
        }
    }

    public function close(User $actor, string $id, string $fingerprint, int $version, string $decision): BalanceAdjustmentRequest
    {
        if (! in_array($decision, ['rejected', 'cancelled', 'expired'], true)) {
            throw new BalanceAdjustmentRejected('invalid_decision');
        }
        $actor = $this->owner($actor);

        return DB::transaction(function () use ($actor, $id, $fingerprint, $version, $decision): BalanceAdjustmentRequest {
            $actor = $this->owner(User::query()->whereKey($actor->id)->sharedLock()->firstOrFail());
            $preview = $this->boundRequest($actor, $id, $fingerprint, $version, true);
            if ($preview->state === $decision) {
                return $preview;
            }
            if ($preview->state !== 'pending' || ($decision === 'expired' && now()->lt($preview->expires_at))) {
                throw new BalanceAdjustmentRejected('preview_unavailable');
            }
            $preview->forceFill(['state' => $decision, 'resolved_at' => now()])->save();
            $this->audit->logWithResolvedContext('balance.adjustment.'.$decision, $preview, new: $this->auditValues($preview), user: $actor);

            return $preview;
        }, 5);
    }

    private function owner(User $actor): User
    {
        $fresh = User::find($actor->id);
        if ($fresh === null || (int) Auth::id() !== (int) $fresh->id || $fresh->role !== UserRole::Owner || ! $fresh->canAccessAdmin()) {
            throw new BalanceAdjustmentRejected('owner_required');
        }
        $this->sessionBinding();

        return $fresh;
    }

    private function sessionBinding(): string
    {
        if (! request()->hasSession() || ! request()->session()->isStarted()) {
            throw new BalanceAdjustmentRejected('authenticated_session_required');
        }

        return hash('sha256', request()->session()->getId());
    }

    private function boundRequest(User $actor, string $id, string $fingerprint, int $version, bool $lock = false): BalanceAdjustmentRequest
    {
        $query = BalanceAdjustmentRequest::query()->whereKey($id);
        $preview = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($preview === null || (int) $preview->owner_user_id !== (int) $actor->id || $preview->version !== $version
            || ! hash_equals($preview->fingerprint, $fingerprint) || $preview->session_binding !== $this->sessionBinding()
            || $preview->credential_binding !== hash('sha256', $actor->password)) {
            throw new BalanceAdjustmentRejected('preview_binding_mismatch');
        }

        return $preview;
    }

    private function bindReceipt(VerifiedMoneyReceipt $receipt, string $id, int $customerId, int $amount): VerifiedMoneyReceipt
    {
        if ($receipt->target->type !== ExternalMoneyReceiptTargetType::BalanceAdjustment) {
            throw new BalanceAdjustmentRejected('receipt_target_mismatch');
        }
        if ($receipt->currency !== 'IDR' || $receipt->amountUnits !== (string) $amount || $receipt->decimals !== 0
            || ($receipt->evidence['customer_id'] ?? null) !== $customerId
            || ($receipt->evidence['ownership_verified'] ?? false) !== true) {
            throw new BalanceAdjustmentRejected('receipt_customer_or_money_mismatch');
        }

        return new VerifiedMoneyReceipt($receipt->identity, $receipt->beneficiary, 'IDR', (string) $amount, 0,
            MoneyReceiptTarget::balanceAdjustment($id), $receipt->evidence, $receipt->aliases, $receipt->parentClaim);
    }

    private function exactAmount(mixed $amount): int
    {
        if ((! is_int($amount) && ! is_string($amount)) || preg_match('/^[1-9][0-9]{0,18}$/D', (string) $amount) !== 1
            || (strlen((string) $amount) === strlen((string) PHP_INT_MAX) && strcmp((string) $amount, (string) PHP_INT_MAX) > 0)) {
            throw new BalanceAdjustmentRejected('invalid_amount_or_reason');
        }

        return (int) $amount;
    }

    private function registeredCustomer(mixed $telegramId): ?Customer
    {
        if (! is_string($telegramId)
            || ! preg_match('/^[1-9][0-9]{0,18}$/D', $telegramId)
            || strlen($telegramId) > strlen((string) PHP_INT_MAX)
            || (strlen($telegramId) === strlen((string) PHP_INT_MAX) && strcmp($telegramId, (string) PHP_INT_MAX) > 0)) {
            throw new BalanceAdjustmentRejected('invalid_telegram_id');
        }

        return Customer::query()->where('telegram_id', $telegramId)->first();
    }

    private function auditValues(BalanceAdjustmentRequest $preview): array
    {
        return $preview->only(['customer_id', 'owner_user_id', 'currency', 'amount', 'reason', 'category', 'source_id', 'fingerprint', 'state', 'ledger_entry_id']);
    }
}
