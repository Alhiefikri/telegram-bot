<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingRail;
use App\Enums\CustomerUsdtFundingReservationOutcome;
use App\Models\Customer;
use App\Models\CustomerUsdtDepositReceiver;
use App\Models\CustomerUsdtFundingIntent;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtTopUp;
use App\Models\ManualPaymentSetting;
use App\Models\TelegramNavigationRoot;
use App\Services\Bsc\BscReadiness;
use App\Services\UsdtBalance\UsdtMicroAmount;
use App\Services\UsdtReceivers\UsdtDepositReceiverRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Confirmation owns DB facts only. No rail, RPC, provider or Telegram calls. */
final class UsdtFundingIntentService
{
    public function __construct(
        private readonly CustomerUsdtFundingGate $gate,
        private readonly CustomerUsdtFundingReservationService $reservations,
        private readonly UsdtDepositReceiverRegistry $receivers,
    ) {}

    public function open(Customer $customer, string $chatId, int $rootId, int $messageId, UsdtMicroAmount $amount): UsdtFundingIntentResult
    {
        return DB::transaction(function () use ($customer, $chatId, $rootId, $messageId, $amount): UsdtFundingIntentResult {
            // Shared prefix: policy -> Customer -> root. Customer serializes
            // the child intent/receiver locks; U3 writers never acquire Customer.
            $policy = $this->lockPolicy();
            $root = $this->lockRoot($customer, $chatId, $rootId, $messageId);
            if ($root === null) {
                return new UsdtFundingIntentResult('stale');
            }
            if ($policy === null) {
                return new UsdtFundingIntentResult('unavailable');
            }
            $active = $this->reservations->activeForCustomer($customer);
            if ($active !== null) {
                return new UsdtFundingIntentResult('active_target', null,
                    $active->reservation->sourceType === 'customer_usdt_top_up' && Str::isUuid($active->reservation->sourceId)
                        ? CustomerUsdtTopUp::query()->whereKey($active->reservation->sourceId)->where('customer_id', $customer->id)->first() : null);
            }
            if (! $this->available()) {
                return new UsdtFundingIntentResult('unavailable');
            }
            $receiver = CustomerUsdtDepositReceiver::query()->where('customer_id', $customer->id)
                ->whereNotNull('verified_at')->whereNull('retired_at')->lockForUpdate()->first();
            $settings = ManualPaymentSetting::query()->where('is_active', true)->lockForUpdate()->first();
            if ($receiver === null || $settings === null || $policy->applicationFeeMicro() > PHP_INT_MAX - $amount->units()) {
                return new UsdtFundingIntentResult('unavailable');
            }
            $snapshot = $this->receivers->snapshotFor($customer);
            if ($snapshot->tokenContract !== BscReadiness::APPROVED_USDT_CONTRACT || $snapshot->tokenDecimals !== 18
                || DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $snapshot->receiverId)->whereNotNull('review_reason')->exists()) {
                return new UsdtFundingIntentResult('unavailable');
            }
            $terms = [
                'receiver_address' => $snapshot->address, 'chain_id' => $snapshot->chainId,
                'token_contract' => $snapshot->tokenContract, 'token_decimals' => $snapshot->tokenDecimals,
                'custody_namespace' => $snapshot->custodyNamespace,
                'minimum_confirmations' => $settings->bscMinConfirmations(), 'requires_finalized_block' => true,
                'payment_ttl_seconds' => $settings->bscPaymentTtlMinutes() * 60,
            ];
            $identity = [
                'customer_id' => (int) $customer->id, 'telegram_navigation_root_id' => $rootId,
                'chat_id' => $chatId, 'canonical_message_id' => $messageId, 'root_generation' => (int) $root->generation,
                'currency' => 'USDT', 'rail' => CustomerUsdtFundingRail::BscUsdt->value,
                'amount_micro' => $amount->units(), 'fee_micro' => $policy->applicationFeeMicro(),
                'payable_micro' => $amount->units() + $policy->applicationFeeMicro(),
                'policy_version' => $policy->policyVersion(), 'terms_version' => $policy->termsVersion(),
                'receiver_id' => $snapshot->receiverId, 'payment_snapshot' => $terms,
            ];
            $current = CustomerUsdtFundingIntent::query()->where('customer_id', $customer->id)->where('state', 'pending')->lockForUpdate()->first();
            if ($current !== null) {
                if ($current->expires_at->isFuture() && $current->only(array_keys($identity)) === $identity) {
                    return new UsdtFundingIntentResult('replayed', $current);
                }
                $this->transition($current, $current->expires_at->isFuture() ? 'superseded' : 'expired');
            }
            $nonce = bin2hex(random_bytes(16));
            $expiresAt = now()->startOfSecond()->addSeconds($policy->confirmationTtlSeconds());
            $fingerprint = hash('sha256', json_encode([
                'v' => 1, 'identity' => $identity, 'nonce' => $nonce, 'expires_at_epoch' => $expiresAt->getTimestamp(),
            ], JSON_THROW_ON_ERROR));
            $intent = CustomerUsdtFundingIntent::query()->create($identity + [
                'nonce' => $nonce, 'request_fingerprint' => $fingerprint, 'state' => 'pending',
                'version' => 1, 'created_at' => now(), 'expires_at' => $expiresAt,
            ]);

            return new UsdtFundingIntentResult('opened', $intent);
        }, 5);
    }

    public function claim(Customer $customer, string $chatId, int $rootId, int $messageId, string $nonce, string $action): UsdtFundingIntentResult
    {
        if (! in_array($action, ['confirm', 'cancel'], true) || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            return new UsdtFundingIntentResult('stale');
        }

        return DB::transaction(function () use ($customer, $chatId, $rootId, $messageId, $nonce, $action): UsdtFundingIntentResult {
            $policy = $this->lockPolicy();
            $root = $this->lockRoot($customer, $chatId, $rootId, $messageId);
            if ($root === null) {
                return new UsdtFundingIntentResult('stale');
            }
            $intent = CustomerUsdtFundingIntent::query()->where('customer_id', $customer->id)->where('nonce', $nonce)->lockForUpdate()->first();
            if ($intent === null || $intent->chat_id !== $chatId || $intent->telegram_navigation_root_id !== $rootId
                || $intent->canonical_message_id !== $messageId || $intent->root_generation !== (int) $root->generation) {
                return new UsdtFundingIntentResult('stale');
            }
            // Replays never re-reserve using a changed policy or create new work.
            if ($intent->state === 'confirmed') {
                return new UsdtFundingIntentResult('confirmed', $intent, CustomerUsdtTopUp::query()->where('intent_id', $intent->id)->firstOrFail());
            }
            if ($intent->state !== 'pending') {
                return new UsdtFundingIntentResult($intent->state, $intent);
            }
            if (! $intent->expires_at->isFuture()) {
                $this->transition($intent, 'expired');

                return new UsdtFundingIntentResult('expired', $intent);
            }
            if ($action === 'cancel') {
                $this->transition($intent, 'cancelled');

                return new UsdtFundingIntentResult('cancelled', $intent);
            }
            if ($policy === null || $policy->policyVersion() !== $intent->policy_version || $policy->termsVersion() !== $intent->terms_version) {
                $this->transition($intent, 'superseded');

                return new UsdtFundingIntentResult('stale_policy', $intent);
            }
            $receiver = CustomerUsdtDepositReceiver::query()->whereKey($intent->receiver_id)->lockForUpdate()->first();
            $settings = ManualPaymentSetting::query()->where('is_active', true)->lockForUpdate()->first();
            if ($receiver === null || ! $receiver->isReadyForAssignment() || $settings === null
                || $settings->bscMinConfirmations() !== $intent->payment_snapshot['minimum_confirmations']
                || $settings->bscPaymentTtlMinutes() * 60 !== $intent->payment_snapshot['payment_ttl_seconds']
                || DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $intent->receiver_id)->whereNotNull('review_reason')->exists()) {
                $this->transition($intent, 'superseded');

                return new UsdtFundingIntentResult('stale_terms', $intent);
            }
            if (! $this->available()) {
                return new UsdtFundingIntentResult('unavailable', $intent);
            }
            $expiresAt = CarbonImmutable::now()->startOfSecond()->addSeconds($intent->payment_snapshot['payment_ttl_seconds']);
            $id = (string) Str::uuid();
            $reserved = $this->reservations->reserve($customer, CustomerUsdtFundingRail::BscUsdt,
                UsdtMicroAmount::fromUnits($intent->amount_micro), 'customer_usdt_top_up', $id, $expiresAt);
            if ($reserved->outcome === CustomerUsdtFundingReservationOutcome::ExistingActive) {
                return new UsdtFundingIntentResult('active_target', $intent,
                    $reserved->reservation->sourceType === 'customer_usdt_top_up' && Str::isUuid($reserved->reservation->sourceId)
                        ? CustomerUsdtTopUp::query()->whereKey($reserved->reservation->sourceId)->where('customer_id', $customer->id)->first() : null);
            }
            $target = CustomerUsdtTopUp::query()->create([
                'id' => $id, 'intent_id' => $intent->id, 'reservation_id' => $reserved->reservation->reservationId,
                'customer_id' => $customer->id, 'reference' => 'usdt:'.$id, 'currency' => 'USDT', 'rail' => $intent->rail,
                'amount_micro' => $intent->amount_micro, 'fee_micro' => $intent->fee_micro, 'payable_micro' => $intent->payable_micro,
                'policy_version' => $intent->policy_version, 'terms_version' => $intent->terms_version,
                'receiver_id' => $intent->receiver_id, 'payment_snapshot' => $intent->payment_snapshot,
                'request_fingerprint' => $intent->request_fingerprint, 'expires_at' => $expiresAt, 'created_at' => now(),
            ]);
            DB::table('customer_usdt_top_up_work')->insert([
                'top_up_id' => $id, 'state' => 'pending', 'version' => 1, 'attempts' => 0,
                'next_attempt_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('customer_usdt_top_up_settlement_work')->insert([
                'top_up_id' => $id, 'next_check_at' => now(), 'updated_at' => now(),
            ]);
            $this->transition($intent, 'confirmed');

            return new UsdtFundingIntentResult('confirmed', $intent, $target);
        }, 5);
    }

    public function expire(int $limit = 100): int
    {
        $ids = CustomerUsdtFundingIntent::query()->where('state', 'pending')->where('expires_at', '<=', now())
            ->orderBy('id')->limit(max(1, min(1000, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id): int {
                $intent = CustomerUsdtFundingIntent::query()->whereKey($id)->lockForUpdate()->first();
                if ($intent === null || $intent->state !== 'pending' || $intent->expires_at->isFuture()) {
                    return 0;
                }
                $this->transition($intent, 'expired');

                return 1;
            }, 5);
        }

        return $count;
    }

    private function lockPolicy(): ?CustomerUsdtFundingPolicy
    {
        return CustomerUsdtFundingPolicy::query()->where('singleton_key', CustomerUsdtFundingPolicy::SINGLETON_KEY)->lockForUpdate()->first();
    }

    private function lockRoot(Customer $customer, string $chatId, int $rootId, int $messageId): ?TelegramNavigationRoot
    {
        $registered = Customer::query()->whereKey($customer->id)->lockForUpdate()->first();
        if ($registered === null || preg_match('/\A[1-9][0-9]*\z/D', $chatId) !== 1 || (string) $registered->telegram_id !== $chatId) {
            return null;
        }

        return TelegramNavigationRoot::query()->whereKey($rootId)->where('customer_id', $customer->id)
            ->where('chat_id', $chatId)->where('message_id', $messageId)->lockForUpdate()->first();
    }

    private function available(): bool
    {
        // U5 technical qualification is local/testing only. H1 is still no-go.
        return app()->environment(['local', 'testing']) && $this->gate->availability(CustomerUsdtFundingRail::BscUsdt)->available;
    }

    private function transition(CustomerUsdtFundingIntent $intent, string $state): void
    {
        $intent->forceFill(['state' => $state, 'version' => $intent->version + 1, 'claimed_at' => now()])->save();
    }
}
