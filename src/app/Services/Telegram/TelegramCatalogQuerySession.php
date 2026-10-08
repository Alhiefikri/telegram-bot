<?php

namespace App\Services\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Cache;

final class TelegramCatalogQuerySession
{
    private const TTL_MINUTES = 15;

    public function key(Customer $customer): string
    {
        return 'telegram:catalog-query:'.$customer->telegram_id;
    }

    public function putSearch(Customer $customer, string $search, int $page = 1, array $familyIds = []): ?string
    {
        $search = trim($search);
        if ($search === '' || $page < 1) {
            $this->forget($customer);

            return null;
        }

        return $this->put($customer, [
            'type' => 'search',
            'search' => $search,
            'page' => $page,
        ], $familyIds);
    }

    public function putCategory(Customer $customer, string $category, int $page, array $familyIds = []): ?string
    {
        if ($category === '' || $page < 1) {
            $this->forget($customer);

            return null;
        }

        return $this->put($customer, [
            'type' => 'category',
            'category' => $category,
            'page' => $page,
        ], $familyIds);
    }

    public function putCatalog(Customer $customer, int $page, array $familyIds = []): ?string
    {
        if ($page < 1) {
            $this->forget($customer);

            return null;
        }

        return $this->put($customer, [
            'type' => 'catalog',
            'page' => $page,
        ], $familyIds);
    }

    /**
     * @param  array<string, mixed>|null  $origin
     */
    public function putFamily(Customer $customer, int $familyId, int $page, int $returnPage, ?string $originToken = null, ?array $origin = null): void
    {
        if ($familyId < 1 || $page < 1 || $returnPage < 1) {
            $this->forget($customer);

            return;
        }

        $safeOrigin = $this->normalizeOrigin($originToken, $origin);
        Cache::put($this->key($customer), [
            'type' => 'family',
            'familyId' => $familyId,
            'page' => $page,
            'returnPage' => $returnPage,
            'origin' => $safeOrigin,
            'token' => bin2hex(random_bytes(8)),
        ], now()->addMinutes(self::TTL_MINUTES));
    }

    /** @param array{type:'search'|'category'|'catalog',search?:string,category?:string,page?:int} $state */
    private function put(Customer $customer, array $state, array $familyIds = []): string
    {
        $token = bin2hex(random_bytes(8));
        $payload = [...$state, 'token' => $token];
        $familyIds = $this->sanitizeFamilyIds($familyIds);
        if ($familyIds !== []) {
            $payload['familyIds'] = $familyIds;
        }
        Cache::put($this->key($customer), $payload, now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /** @return array<string, mixed>|null */
    public function state(Customer $customer): ?array
    {
        return $this->normalizeState(Cache::get($this->key($customer)));
    }

    /** @return array<string, mixed>|null */
    public function stateForToken(Customer $customer, string $token): ?array
    {
        $state = $this->state($customer);

        return $state !== null && hash_equals((string) $state['token'], $token) ? $state : null;
    }

    /** @return array<string, mixed>|null */
    public function familyState(Customer $customer): ?array
    {
        $state = $this->state($customer);

        return $state !== null && ($state['type'] ?? null) === 'family' ? $state : null;
    }

    /**
     * The browse list a token resolves to: the origin snapshot a family screen
     * captured, or the current browse state itself when a Product Detail was
     * opened straight from that list.
     *
     * @return array<string, mixed>|null
     */
    public function originForToken(Customer $customer, string $token): ?array
    {
        $state = $this->state($customer);
        if ($state === null) {
            return null;
        }

        if (($state['type'] ?? null) !== 'family') {
            return hash_equals((string) $state['token'], $token) ? $state : null;
        }

        $origin = $state['origin'] ?? null;

        return is_array($origin)
            && is_string($origin['token'] ?? null)
            && hash_equals($origin['token'], $token)
            ? $origin
            : null;
    }

    public function search(Customer $customer): ?string
    {
        $state = $this->state($customer);

        return $state !== null && ($state['type'] ?? null) === 'search' && is_string($state['search'] ?? null)
            ? $state['search']
            : null;
    }

    public function token(Customer $customer): ?string
    {
        return $this->state($customer)['token'] ?? null;
    }

    public function searchForToken(Customer $customer, string $token): ?string
    {
        $state = $this->stateForToken($customer, $token);

        return $state !== null && ($state['type'] ?? null) === 'search' && is_string($state['search'] ?? null)
            ? $state['search']
            : null;
    }

    public function forget(Customer $customer): void
    {
        Cache::forget($this->key($customer));
    }

    /** @return array<string, mixed>|null */
    private function normalizeState(mixed $state): ?array
    {
        if (! is_array($state)
            || ! is_string($state['token'] ?? null)
            || preg_match('/\A[a-f0-9]{16}\z/D', $state['token']) !== 1) {
            return null;
        }
        $familyIds = array_key_exists('familyIds', $state)
            ? $this->normalizeFamilyIds($state['familyIds'])
            : null;
        if (array_key_exists('familyIds', $state) && $familyIds === null) {
            return null;
        }

        if (($state['type'] ?? null) === 'search'
            && is_string($state['search'] ?? null)
            && $state['search'] !== ''
            && (! isset($state['page']) || (is_int($state['page']) && $state['page'] > 0))) {
            return $this->withFamilyIds([
                'type' => 'search',
                'search' => $state['search'],
                'page' => is_int($state['page'] ?? null) && $state['page'] > 0 ? $state['page'] : 1,
                'token' => $state['token'],
            ], $familyIds);
        }

        if (($state['type'] ?? null) === 'category'
            && is_string($state['category'] ?? null)
            && $state['category'] !== ''
            && is_int($state['page'] ?? null)
            && $state['page'] > 0) {
            return $this->withFamilyIds([
                'type' => 'category',
                'category' => $state['category'],
                'page' => $state['page'],
                'token' => $state['token'],
            ], $familyIds);
        }
        if (($state['type'] ?? null) === 'catalog'
            && is_int($state['page'] ?? null)
            && $state['page'] > 0) {
            return $this->withFamilyIds([
                'type' => 'catalog',
                'page' => $state['page'],
                'token' => $state['token'],
            ], $familyIds);
        }

        if (($state['type'] ?? null) === 'family'
            && is_int($state['familyId'] ?? null)
            && $state['familyId'] > 0
            && is_int($state['page'] ?? null)
            && $state['page'] > 0
            && is_int($state['returnPage'] ?? null)
            && $state['returnPage'] > 0) {
            return [
                'type' => 'family',
                'familyId' => $state['familyId'],
                'page' => $state['page'],
                'returnPage' => $state['returnPage'],
                'origin' => $this->normalizeOrigin(
                    is_array($state['origin'] ?? null) ? ($state['origin']['token'] ?? null) : null,
                    is_array($state['origin'] ?? null) ? $state['origin'] : null,
                ),
                'token' => $state['token'],
            ];
        }

        return null;
    }

    /** @param array<string, mixed>|null $origin */
    private function normalizeOrigin(?string $token, ?array $origin): ?array
    {
        if ($token === null || preg_match('/\A[a-f0-9]{16}\z/D', $token) !== 1 || ! is_array($origin)) {
            return null;
        }
        $familyIds = array_key_exists('familyIds', $origin)
            ? $this->normalizeFamilyIds($origin['familyIds'])
            : null;
        if (array_key_exists('familyIds', $origin) && $familyIds === null) {
            return null;
        }

        $type = $origin['type'] ?? null;
        if ($type === 'search'
            && is_string($origin['search'] ?? null)
            && $origin['search'] !== ''
            && (! isset($origin['page']) || (is_int($origin['page']) && $origin['page'] > 0))) {
            return $this->withFamilyIds([
                'type' => 'search',
                'search' => $origin['search'],
                'page' => is_int($origin['page'] ?? null) && $origin['page'] > 0 ? $origin['page'] : 1,
                'token' => $token,
            ], $familyIds);
        }
        if ($type === 'category'
            && is_string($origin['category'] ?? null)
            && $origin['category'] !== ''
            && is_int($origin['page'] ?? null)
            && $origin['page'] > 0) {
            return $this->withFamilyIds([
                'type' => 'category',
                'category' => $origin['category'],
                'page' => $origin['page'],
                'token' => $token,
            ], $familyIds);
        }

        if ($type === 'catalog'
            && is_int($origin['page'] ?? null)
            && $origin['page'] > 0) {
            return $this->withFamilyIds([
                'type' => 'catalog',
                'page' => $origin['page'],
                'token' => $token,
            ], $familyIds);
        }

        return null;
    }

    /** @param array<string, mixed> $state */
    private function withFamilyIds(array $state, ?array $familyIds): array
    {
        if ($familyIds !== null) {
            $state['familyIds'] = $familyIds;
        }

        return $state;
    }

    /** @return list<int>|null */
    private function normalizeFamilyIds(mixed $familyIds): ?array
    {
        if (! is_array($familyIds)) {
            return null;
        }

        $normalized = [];
        foreach ($familyIds as $familyId) {
            if (! is_int($familyId) || $familyId < 1) {
                return null;
            }
            $normalized[$familyId] = $familyId;
        }

        return array_values($normalized);
    }

    /** @param list<int> $familyIds */
    private function sanitizeFamilyIds(array $familyIds): array
    {
        return $this->normalizeFamilyIds($familyIds) ?? [];
    }
}
