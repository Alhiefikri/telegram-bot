<?php

namespace App\Services\StoreBalance;

use App\Exceptions\StoreBalanceFundingPolicyConflict;
use App\Models\StoreBalanceFundingPolicy;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

final class StoreBalanceFundingPolicyManager
{
    private const CALLBACK_PREFIX = 'balance:p:';

    private const CALLBACK_TOKEN_BYTES = 8;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function current(): StoreBalanceFundingPolicyState
    {
        return $this->state($this->record());
    }

    public function change(
        mixed $minimumAmount,
        mixed $presetAmounts,
        int $expectedVersion,
        User $actor,
        ?string $ip,
    ): StoreBalanceFundingPolicyChange {
        $definition = StoreBalanceFundingPolicyDefinition::from($minimumAmount, $presetAmounts);

        return DB::transaction(function () use ($definition, $expectedVersion, $actor, $ip): StoreBalanceFundingPolicyChange {
            $policy = $this->lockedRecord();
            $oldState = $this->state($policy);

            if ($expectedVersion !== $oldState->version) {
                throw new StoreBalanceFundingPolicyConflict(
                    'Kebijakan top-up Saldo berubah sebelum permintaan ini selesai. Muat ulang dashboard lalu simpan ulang.',
                );
            }

            if ($oldState->matches($definition)) {
                return new StoreBalanceFundingPolicyChange($oldState, $oldState, false);
            }

            $policy->forceFill([
                'minimum_amount' => $definition->minimumAmount,
                'preset_amounts' => $definition->presetAmounts,
                'version' => $oldState->version + 1,
            ])->save();

            $policy->refresh();
            $newState = $this->state($policy);

            // The policy and its audit trail must commit or roll back together.
            $this->auditLogger->log(
                'store_balance.funding_policy.updated',
                $policy,
                $oldState->auditValues(),
                $newState->auditValues(),
                $actor,
                $ip,
            );

            return new StoreBalanceFundingPolicyChange($oldState, $newState, true);
        }, 5);
    }

    public static function isPresetCallbackFormat(string $data): bool
    {
        return strlen($data) <= 64
            && preg_match('/\Abalance:p:(?:0|[1-9][0-9]*):[A-Za-z0-9_-]{11}\z/D', $data) === 1;
    }

    public function presetCallback(StoreBalanceFundingPolicyState $policy, int $index): string
    {
        if (! array_key_exists($index, $policy->presetAmounts)) {
            throw new InvalidArgumentException('Store Balance preset index is invalid.');
        }

        return self::CALLBACK_PREFIX.$policy->version.':'.$this->callbackIdentity(
            $policy->version,
            $index,
            $policy->presetAmounts[$index],
        );
    }

    public function resolvePresetCallback(string $data): ?StoreBalanceFundingPresetSelection
    {
        if (! self::isPresetCallbackFormat($data)) {
            return null;
        }

        preg_match('/\Abalance:p:(?<version>0|[1-9][0-9]*):(?<identity>[A-Za-z0-9_-]{11})\z/D', $data, $matches);
        $version = $this->parseVersion($matches['version'] ?? '');
        if ($version === null) {
            return null;
        }

        $policy = $this->current();
        if ($policy->version !== $version) {
            return null;
        }

        $identity = (string) ($matches['identity'] ?? '');
        foreach ($policy->presetAmounts as $index => $amount) {
            if (hash_equals($this->callbackIdentity($policy->version, $index, $amount), $identity)) {
                return new StoreBalanceFundingPresetSelection($policy->version, $index, $amount);
            }
        }

        return null;
    }

    private function record(): StoreBalanceFundingPolicy
    {
        return StoreBalanceFundingPolicy::query()
            ->where('singleton_key', StoreBalanceFundingPolicy::SINGLETON_KEY)
            ->firstOrFail();
    }

    private function lockedRecord(): StoreBalanceFundingPolicy
    {
        return StoreBalanceFundingPolicy::query()
            ->where('singleton_key', StoreBalanceFundingPolicy::SINGLETON_KEY)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function state(StoreBalanceFundingPolicy $policy): StoreBalanceFundingPolicyState
    {
        $definition = StoreBalanceFundingPolicyDefinition::from(
            $policy->minimumAmount(),
            $policy->presetAmounts(),
        );

        return new StoreBalanceFundingPolicyState(
            minimumAmount: $definition->minimumAmount,
            presetAmounts: $definition->presetAmounts,
            version: $policy->policyVersion(),
        );
    }

    private function callbackIdentity(int $version, int $index, int $amount): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('Application key is required for Store Balance callbacks.');
        }

        $digest = hash_hmac('sha256', $version.'|'.$index.'|'.$amount, $key, true);

        return rtrim(strtr(base64_encode(substr($digest, 0, self::CALLBACK_TOKEN_BYTES)), '+/', '-_'), '=');
    }

    private function parseVersion(string $value): ?int
    {
        if ($value === ''
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $value;
    }
}
