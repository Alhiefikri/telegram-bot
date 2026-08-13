<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

class TelegramBroadcastDrafts
{
    private const TTL_MINUTES = 15;

    private const LOCK_LEASE_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 3;

    /**
     * @param  array{message: string, photo_file_id: ?string, product_id: ?int, draft_id?: string}  $draft
     */
    public function put(Customer $customer, array $draft): void
    {
        $draft['draft_id'] = $this->draftId($draft['draft_id'] ?? null);

        $this->synchronized($customer, function () use ($customer, $draft): void {
            $expiresAt = $this->draftExpiresAt($this->get($customer)) ?? now()->addMinutes(self::TTL_MINUTES);
            $draft['expires_at'] = $expiresAt->toISOString();

            $this->cacheDraft($customer, $draft, $expiresAt);
        });
    }

    /**
     * @return array{message: string, photo_file_id: ?string, product_id: ?int, draft_id: string, expires_at: string}|null
     */
    public function get(Customer $customer): ?array
    {
        $draft = Cache::get($this->key($customer));

        if (! $this->validDraft($draft)) {
            return null;
        }

        $draft['photo_file_id'] ??= null;

        return $draft;
    }

    public function forget(Customer $customer, ?string $draftId = null): bool
    {
        if ($draftId === null) {
            return (bool) $this->synchronized($customer, function () use ($customer): bool {
                if ($this->get($customer) === null) {
                    return false;
                }

                Cache::forget($this->key($customer));

                return true;
            });
        }

        return $this->mutate($customer, $draftId, fn (array $draft): array => [
            'action' => 'remove',
            'value' => true,
        ])['matched'];
    }

    /**
     * Apply a mutation only when the supplied draft identity is current.
     *
     * The callback must return an action of keep, replace, or remove.
     *
     * @param  Closure(array{message: string, photo_file_id: ?string, product_id: ?int, draft_id: string, expires_at: string}): array{action: 'keep'|'replace'|'remove', draft?: array{message: string, photo_file_id: ?string, product_id: ?int, draft_id: string, expires_at?: string}, value?: mixed}  $mutation
     * @return array{locked: bool, matched: bool, value: mixed}
     */
    public function mutate(Customer $customer, string $draftId, Closure $mutation): array
    {
        return $this->synchronized($customer, function () use ($customer, $draftId, $mutation): array {
            $draft = $this->get($customer);
            if ($draft === null || ! hash_equals($draft['draft_id'], $draftId)) {
                return ['locked' => true, 'matched' => false, 'value' => null];
            }

            $operation = $mutation($draft);
            $action = $operation['action'] ?? 'keep';

            if ($action === 'replace') {
                $replacement = $operation['draft'] ?? $draft;
                $expiresAt = $this->draftExpiresAt($draft) ?? now()->addMinutes(self::TTL_MINUTES);
                $replacement['draft_id'] = $draft['draft_id'];
                $replacement['expires_at'] = $expiresAt->toISOString();
                $this->cacheDraft($customer, $replacement, $expiresAt);
            } elseif ($action === 'remove') {
                Cache::forget($this->key($customer));
            }

            return [
                'locked' => true,
                'matched' => true,
                'value' => $operation['value'] ?? null,
            ];
        });
    }

    /**
     * @return array{message: string, photo_file_id: ?string, product_id: ?int, draft_id: string, expires_at: string}|null
     */
    public function consume(Customer $customer, ?string $draftId = null): ?array
    {
        $draft = $this->get($customer);
        if ($draft === null || ($draftId !== null && ! hash_equals($draft['draft_id'], $draftId))) {
            return null;
        }

        $result = $this->mutate($customer, $draft['draft_id'], fn (array $current): array => [
            'action' => 'remove',
            'value' => $current,
        ]);

        return $result['matched'] && is_array($result['value']) ? $result['value'] : null;
    }

    private function synchronized(Customer $customer, Closure $callback): mixed
    {
        return Cache::lock($this->lockKey($customer), self::LOCK_LEASE_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    private function draftId(?string $draftId): string
    {
        return is_string($draftId) && preg_match('/^[A-Za-z0-9]{16}$/', $draftId) === 1
            ? $draftId
            : Str::random(16);
    }

    private function validDraft(mixed $draft): bool
    {
        return is_array($draft)
            && is_string($draft['message'] ?? null)
            && array_key_exists('product_id', $draft)
            && (is_int($draft['product_id']) || $draft['product_id'] === null)
            && (! array_key_exists('photo_file_id', $draft)
                || $draft['photo_file_id'] === null
                || (is_string($draft['photo_file_id']) && $draft['photo_file_id'] !== ''))
            && is_string($draft['draft_id'] ?? null)
            && preg_match('/^[A-Za-z0-9]{16}$/', $draft['draft_id']) === 1
            && ($expiresAt = $this->draftExpiresAt($draft)) !== null
            && $expiresAt->isFuture();
    }

    private function cacheDraft(Customer $customer, array $draft, Carbon $expiresAt): void
    {
        Cache::put($this->key($customer), $draft, $expiresAt);
    }

    private function draftExpiresAt(mixed $draft): ?Carbon
    {
        $expiresAt = is_array($draft) ? ($draft['expires_at'] ?? null) : null;
        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return null;
        }

        try {
            return Carbon::parse($expiresAt);
        } catch (Throwable) {
            return null;
        }
    }

    private function key(Customer $customer): string
    {
        return 'telegram:broadcast-draft:'.$customer->telegram_id;
    }

    private function lockKey(Customer $customer): string
    {
        return $this->key($customer).':lock';
    }
}
