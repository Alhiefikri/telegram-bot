<?php

namespace App\Services\UsdtFunding;

use App\Enums\CustomerUsdtFundingPauseScope;
use App\Exceptions\CustomerUsdtFundingPolicyConflict;
use App\Models\CustomerUsdtFundingPause;
use App\Models\CustomerUsdtFundingPolicy;
use App\Models\CustomerUsdtFundingTerms;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Owner of the versioned Customer USDT funding policy and its pause controls.
 * Every mutation is optimistic-versioned, audited and money-free: changing the
 * policy, publishing new terms or pausing/resuming never creates a ledger
 * entry, a reservation or a provider target. The presentation-only USD preset
 * list is stored here too, but it only seeds the top-up amount buttons. The
 * policy row lock is the serialization point shared with the reservation
 * service, so an activation, pause or resume can never interleave with a new
 * funding target.
 */
final class CustomerUsdtFundingPolicyManager
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function current(): CustomerUsdtFundingPolicyState
    {
        return $this->state($this->record());
    }

    public function currentOrNull(): ?CustomerUsdtFundingPolicyState
    {
        $policy = CustomerUsdtFundingPolicy::current();

        return $policy === null ? null : $this->state($policy);
    }

    public function termsBody(int $version): string
    {
        $terms = CustomerUsdtFundingTerms::query()->where('version', $version)->first();
        if ($terms === null) {
            throw new InvalidArgumentException('Customer USDT funding terms version does not exist.');
        }

        return (string) $terms->body;
    }

    /**
     * Applies one validated policy edit. An omitted $presetAmounts keeps the
     * presets already stored, so callers that only change the money-neutral
     * fields never rewrite the presentation list.
     */
    public function change(
        mixed $fundingEnabled,
        mixed $confirmationTtlSeconds,
        mixed $applicationFeeMicro,
        mixed $termsBody,
        int $expectedVersion,
        User $actor,
        ?string $ip,
        mixed $presetAmounts = null,
    ): CustomerUsdtFundingPolicyChange {
        $definition = CustomerUsdtFundingPolicyDefinition::from(
            $fundingEnabled,
            $confirmationTtlSeconds,
            $applicationFeeMicro,
            $termsBody,
            $presetAmounts ?? $this->storedPresetAmounts(),
        );

        return DB::transaction(function () use ($definition, $expectedVersion, $actor, $ip): CustomerUsdtFundingPolicyChange {
            $policy = $this->lockedRecord();
            $oldState = $this->state($policy);

            if ($expectedVersion !== $oldState->version) {
                throw new CustomerUsdtFundingPolicyConflict(
                    'Kebijakan top-up USDT berubah sebelum permintaan ini selesai. Muat ulang dashboard lalu simpan ulang.',
                );
            }

            $termsChanged = $this->termsBody($oldState->termsVersion) !== $definition->termsBody;
            $unchanged = ! $termsChanged
                && $oldState->fundingEnabled === $definition->fundingEnabled
                && $oldState->confirmationTtlSeconds === $definition->confirmationTtlSeconds
                && $oldState->applicationFeeMicro === $definition->applicationFeeMicro
                && $oldState->presetAmountsMicro === $definition->presetAmountsMicro;

            if ($unchanged) {
                return new CustomerUsdtFundingPolicyChange($oldState, $oldState, false);
            }

            $termsVersion = $oldState->termsVersion;
            if ($termsChanged) {
                $termsVersion = (int) CustomerUsdtFundingTerms::query()->max('version') + 1;
                CustomerUsdtFundingTerms::query()->create([
                    'version' => $termsVersion,
                    'body' => $definition->termsBody,
                    'created_by_user_id' => $actor->getKey(),
                    'created_at' => now(),
                ]);
            }

            $policy->forceFill([
                'funding_enabled' => $definition->fundingEnabled,
                'confirmation_ttl_seconds' => $definition->confirmationTtlSeconds,
                'application_fee_micro' => $definition->applicationFeeMicro,
                'preset_amounts_micro' => $definition->presetAmountsMicro,
                'terms_version' => $termsVersion,
                'version' => $oldState->version + 1,
            ])->save();

            $policy->refresh();
            $newState = $this->state($policy);

            // Policy, terms and audit trail commit or roll back together.
            $this->auditLogger->log(
                'customer_usdt.funding_policy.updated',
                $policy,
                $oldState->auditValues(),
                $newState->auditValues(),
                $actor,
                $ip,
            );

            return new CustomerUsdtFundingPolicyChange($oldState, $newState, true);
        }, 5);
    }

    /** @return array<string, CustomerUsdtFundingPauseState> */
    public function pauses(): array
    {
        $states = [];
        foreach (CustomerUsdtFundingPause::query()->orderBy('scope')->get() as $pause) {
            $state = $this->pauseState($pause);
            $states[$state->scope->value] = $state;
        }

        return $states;
    }

    public function isPaused(CustomerUsdtFundingPauseScope $scope): bool
    {
        return CustomerUsdtFundingPause::query()->where('scope', $scope->value)->exists();
    }

    public function pause(CustomerUsdtFundingPauseScope $scope, mixed $reason, User $actor, ?string $ip): bool
    {
        $normalizedReason = $this->normalizeReason($reason);

        return DB::transaction(function () use ($scope, $normalizedReason, $actor, $ip): bool {
            // The policy row is the shared serialization point: a reservation
            // transaction holds it while it checks the pause state.
            $this->lockedRecord();

            $existing = CustomerUsdtFundingPause::query()
                ->where('scope', $scope->value)
                ->lockForUpdate()
                ->first();
            if ($existing !== null) {
                return false;
            }

            $pause = CustomerUsdtFundingPause::query()->create([
                'scope' => $scope->value,
                'reason' => $normalizedReason,
                'paused_by_user_id' => $actor->getKey(),
                'paused_at' => now(),
            ]);

            $this->auditLogger->log(
                'customer_usdt.funding_pause.created',
                $pause,
                null,
                $this->pauseState($pause)->auditValues(),
                $actor,
                $ip,
            );

            return true;
        }, 5);
    }

    public function resume(CustomerUsdtFundingPauseScope $scope, User $actor, ?string $ip): bool
    {
        return DB::transaction(function () use ($scope, $actor, $ip): bool {
            $this->lockedRecord();

            $pause = CustomerUsdtFundingPause::query()
                ->where('scope', $scope->value)
                ->lockForUpdate()
                ->first();
            if ($pause === null) {
                return false;
            }

            $oldValues = $this->pauseState($pause)->auditValues();
            $pause->delete();

            $this->auditLogger->log(
                'customer_usdt.funding_pause.deleted',
                $pause,
                $oldValues,
                null,
                $actor,
                $ip,
            );

            return true;
        }, 5);
    }

    private function record(): CustomerUsdtFundingPolicy
    {
        return CustomerUsdtFundingPolicy::query()
            ->where('singleton_key', CustomerUsdtFundingPolicy::SINGLETON_KEY)
            ->firstOrFail();
    }

    private function lockedRecord(): CustomerUsdtFundingPolicy
    {
        return CustomerUsdtFundingPolicy::query()
            ->where('singleton_key', CustomerUsdtFundingPolicy::SINGLETON_KEY)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * The presets a change request keeps when it omits them: the stored list,
     * or the seeded default list before the singleton exists.
     *
     * @return list<int>
     */
    private function storedPresetAmounts(): array
    {
        return CustomerUsdtFundingPolicy::current()?->presetAmountsMicro()
            ?? CustomerUsdtFundingPolicy::DEFAULT_PRESET_AMOUNTS_MICRO;
    }

    private function state(CustomerUsdtFundingPolicy $policy): CustomerUsdtFundingPolicyState
    {
        return new CustomerUsdtFundingPolicyState(
            fundingEnabled: $policy->fundingEnabled(),
            confirmationTtlSeconds: $policy->confirmationTtlSeconds(),
            applicationFeeMicro: $policy->applicationFeeMicro(),
            presetAmountsMicro: $policy->presetAmountsMicro(),
            termsVersion: $policy->termsVersion(),
            version: $policy->policyVersion(),
        );
    }

    private function pauseState(CustomerUsdtFundingPause $pause): CustomerUsdtFundingPauseState
    {
        return new CustomerUsdtFundingPauseState(
            scope: $pause->scope,
            reason: (string) $pause->reason,
            pausedByUserId: $pause->paused_by_user_id === null ? null : (int) $pause->paused_by_user_id,
            pausedAt: $pause->paused_at?->toIso8601String() ?? '',
        );
    }

    private function normalizeReason(mixed $reason): string
    {
        if (! is_string($reason)) {
            throw new InvalidArgumentException('Alasan jeda top-up USDT wajib diisi.');
        }

        $normalized = trim($reason);
        if (strlen($normalized) < 3 || strlen($normalized) > 191
            || preg_match('/[\x00-\x1F\x7F]/', $normalized) === 1) {
            throw new InvalidArgumentException('Alasan jeda top-up USDT harus 3-191 karakter satu baris.');
        }

        return $normalized;
    }
}
