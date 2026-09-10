<?php

namespace App\Services\StoreBalance;

use App\Enums\StoreBalanceFundingIntentClaimOutcome;
use App\Enums\StoreBalanceFundingIntentStatus;
use App\Models\Customer;
use App\Models\StoreBalanceFundingIntent;
use App\Models\StoreBalanceFundingPolicy;
use Illuminate\Support\Facades\DB;

final class StoreBalanceFundingIntentService
{
    private const CALLBACK_PREFIX = 'balance:';

    private const NONCE_BYTES = 16;

    public const TTL_MINUTES = 10;

    public function __construct(private readonly StoreBalanceFundingAmountValidator $amounts) {}

    public static function isActionCallbackFormat(string $data): bool
    {
        return strlen($data) <= 64
            && preg_match('/\Abalance:(?:confirm|cancel|back):[a-f0-9]{32}\z/D', $data) === 1;
    }

    /** @return array{action: 'confirm'|'cancel'|'back', nonce: string}|null */
    public static function actionFromCallback(string $data): ?array
    {
        if (! self::isActionCallbackFormat($data)) {
            return null;
        }

        preg_match('/\Abalance:(?<action>confirm|cancel|back):(?<nonce>[a-f0-9]{32})\z/D', $data, $matches);

        return [
            'action' => $matches['action'],
            'nonce' => $matches['nonce'],
        ];
    }

    public static function actionCallback(string $action, string $nonce): string
    {
        if (! in_array($action, ['confirm', 'cancel', 'back'], true)
            || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            throw new \InvalidArgumentException('Invalid Store Balance funding intent callback.');
        }

        return self::CALLBACK_PREFIX.$action.':'.$nonce;
    }

    public function open(
        Customer $customer,
        string $chatId,
        int $navigationRootId,
        int $canonicalMessageId,
        StoreBalanceFundingIntentInput $input,
    ): StoreBalanceFundingIntentOpening {
        $chatId = trim($chatId);
        if ($chatId === '' || $navigationRootId < 1 || $canonicalMessageId < 1) {
            return new StoreBalanceFundingIntentOpening(null, 'stale');
        }

        return DB::transaction(function () use (
            $customer,
            $chatId,
            $navigationRootId,
            $canonicalMessageId,
            $input,
        ): StoreBalanceFundingIntentOpening {
            if (Customer::query()->whereKey($customer->id)->lockForUpdate()->first() === null) {
                return new StoreBalanceFundingIntentOpening(null, 'stale');
            }
            if (! $this->rootMatches($customer->id, $navigationRootId, $chatId, $canonicalMessageId)) {
                return new StoreBalanceFundingIntentOpening(null, 'stale');
            }

            $policy = StoreBalanceFundingPolicy::query()
                ->where('singleton_key', StoreBalanceFundingPolicy::SINGLETON_KEY)
                ->lockForUpdate()
                ->first();
            if ($policy === null) {
                return new StoreBalanceFundingIntentOpening(null, 'stale');
            }

            $definition = StoreBalanceFundingPolicyDefinition::from(
                $policy->minimumAmount(),
                $policy->presetAmounts(),
            );
            $state = new StoreBalanceFundingPolicyState(
                minimumAmount: $definition->minimumAmount,
                presetAmounts: $definition->presetAmounts,
                version: $policy->policyVersion(),
            );

            if ($input->isPreset()
                && ($input->policyVersion !== $state->version
                    || $input->presetIndex < 0
                    || ! array_key_exists($input->presetIndex, $state->presetAmounts)
                    || $state->presetAmounts[$input->presetIndex] !== $input->rawAmount)) {
                return new StoreBalanceFundingIntentOpening(null, 'stale_policy');
            }

            $validation = $this->amounts->validate($input->rawAmount, $state);
            if (! $validation->isValid()) {
                return new StoreBalanceFundingIntentOpening(null, $validation->error);
            }

            $amount = $validation->amount;
            $now = now();
            $current = StoreBalanceFundingIntent::query()
                ->where('customer_id', $customer->id)
                ->where('status', StoreBalanceFundingIntentStatus::Pending)
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($current !== null && ! $current->expires_at->isFuture()) {
                $current->forceFill([
                    'status' => StoreBalanceFundingIntentStatus::Expired,
                    'claimed_at' => $now,
                ])->save();
                $current = null;
            }

            if ($current !== null && $current->matches(
                $chatId,
                $navigationRootId,
                $canonicalMessageId,
                $state->version,
                $amount,
            )) {
                return new StoreBalanceFundingIntentOpening($current, null, true);
            }

            if ($current !== null) {
                $current->forceFill([
                    'status' => StoreBalanceFundingIntentStatus::Superseded,
                    'claimed_at' => $now,
                ])->save();
            }

            $intent = StoreBalanceFundingIntent::query()->create([
                'customer_id' => $customer->id,
                'telegram_navigation_root_id' => $navigationRootId,
                'chat_id' => $chatId,
                'canonical_message_id' => $canonicalMessageId,
                'policy_version' => $state->version,
                'amount' => $amount,
                'nonce' => bin2hex(random_bytes(self::NONCE_BYTES)),
                'status' => StoreBalanceFundingIntentStatus::Pending,
                'expires_at' => $now->copy()->addMinutes(self::TTL_MINUTES),
            ]);

            return new StoreBalanceFundingIntentOpening($intent);
        }, 5);
    }

    public function claim(
        Customer $customer,
        string $chatId,
        int $navigationRootId,
        int $canonicalMessageId,
        string $nonce,
        string $action,
    ): StoreBalanceFundingIntentClaim {
        if (! in_array($action, ['confirm', 'cancel', 'back'], true)
            || preg_match('/\A[a-f0-9]{32}\z/D', $nonce) !== 1) {
            return new StoreBalanceFundingIntentClaim(StoreBalanceFundingIntentClaimOutcome::Stale);
        }

        $chatId = trim($chatId);
        if ($chatId === '' || $navigationRootId < 1 || $canonicalMessageId < 1) {
            return new StoreBalanceFundingIntentClaim(StoreBalanceFundingIntentClaimOutcome::Stale);
        }

        return DB::transaction(function () use (
            $customer,
            $chatId,
            $navigationRootId,
            $canonicalMessageId,
            $nonce,
            $action,
        ): StoreBalanceFundingIntentClaim {
            if (Customer::query()->whereKey($customer->id)->lockForUpdate()->first() === null) {
                return new StoreBalanceFundingIntentClaim(StoreBalanceFundingIntentClaimOutcome::Stale);
            }
            if (! $this->rootMatches($customer->id, $navigationRootId, $chatId, $canonicalMessageId)) {
                return new StoreBalanceFundingIntentClaim(StoreBalanceFundingIntentClaimOutcome::Stale);
            }

            $intent = StoreBalanceFundingIntent::query()
                ->where('customer_id', $customer->id)
                ->where('nonce', $nonce)
                ->lockForUpdate()
                ->first();
            if ($intent === null
                || $intent->chat_id !== $chatId
                || $intent->telegram_navigation_root_id !== $navigationRootId
                || $intent->canonical_message_id !== $canonicalMessageId) {
                return new StoreBalanceFundingIntentClaim(StoreBalanceFundingIntentClaimOutcome::Stale);
            }

            if ($intent->status === StoreBalanceFundingIntentStatus::Pending) {
                $policy = StoreBalanceFundingPolicy::query()
                    ->where('singleton_key', StoreBalanceFundingPolicy::SINGLETON_KEY)
                    ->lockForUpdate()
                    ->first();
                if ($policy === null || $policy->policyVersion() !== $intent->policy_version) {
                    $intent->forceFill([
                        'status' => StoreBalanceFundingIntentStatus::Superseded,
                        'claimed_at' => now(),
                    ])->save();

                    return new StoreBalanceFundingIntentClaim(
                        StoreBalanceFundingIntentClaimOutcome::Stale,
                        $intent,
                    );
                }

                if (! $intent->expires_at->isFuture()) {
                    $intent->forceFill([
                        'status' => StoreBalanceFundingIntentStatus::Expired,
                        'claimed_at' => now(),
                    ])->save();

                    return new StoreBalanceFundingIntentClaim(
                        StoreBalanceFundingIntentClaimOutcome::Expired,
                        $intent,
                    );
                }
            }

            return match ($intent->status) {
                StoreBalanceFundingIntentStatus::Pending => $this->claimPending($intent, $action),
                StoreBalanceFundingIntentStatus::Confirmed => new StoreBalanceFundingIntentClaim(
                    StoreBalanceFundingIntentClaimOutcome::AlreadyConfirmed,
                    $intent,
                ),
                StoreBalanceFundingIntentStatus::Cancelled => new StoreBalanceFundingIntentClaim(
                    StoreBalanceFundingIntentClaimOutcome::AlreadyCancelled,
                    $intent,
                ),
                StoreBalanceFundingIntentStatus::Expired => new StoreBalanceFundingIntentClaim(
                    StoreBalanceFundingIntentClaimOutcome::Expired,
                    $intent,
                ),
                StoreBalanceFundingIntentStatus::Superseded => new StoreBalanceFundingIntentClaim(
                    StoreBalanceFundingIntentClaimOutcome::Stale,
                    $intent,
                ),
            };
        }, 5);
    }

    private function rootMatches(int $customerId, int $navigationRootId, string $chatId, int $messageId): bool
    {
        return DB::table('telegram_navigation_roots')
            ->where('id', $navigationRootId)
            ->where('customer_id', $customerId)
            ->where('chat_id', $chatId)
            ->where('message_id', $messageId)
            ->exists();
    }

    private function claimPending(StoreBalanceFundingIntent $intent, string $action): StoreBalanceFundingIntentClaim
    {
        $confirmed = $action === 'confirm';
        $intent->forceFill([
            'status' => $confirmed
                ? StoreBalanceFundingIntentStatus::Confirmed
                : StoreBalanceFundingIntentStatus::Cancelled,
            'claimed_at' => now(),
        ])->save();

        return new StoreBalanceFundingIntentClaim(
            $confirmed
                ? StoreBalanceFundingIntentClaimOutcome::Confirmed
                : StoreBalanceFundingIntentClaimOutcome::Cancelled,
            $intent,
        );
    }
}
