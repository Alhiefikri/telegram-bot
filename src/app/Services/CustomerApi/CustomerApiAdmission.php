<?php

namespace App\Services\CustomerApi;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class CustomerApiAdmission
{
    public const PERMIT_SECONDS = 90;

    public function ip(string $ip): array
    {
        return $this->reserve([['ip', $ip, 600, 60]]);
    }

    public function invalid(string $ip): void
    {
        $this->reserve([['invalid', $ip, 30, 60]]);
    }

    public function authenticated(CustomerApiPrincipal $principal, bool $mutation, bool $delivery): array
    {
        $id = (string) $principal->customerId;
        $limits = $mutation
            ? [['orders', $id, 10, 60], ['global-orders', 'all', 20, 1]]
            : [['reads', $id, 120, 60], ['global-reads', 'all', 300, 1]];
        if ($delivery) {
            $limits[] = ['delivery', $id, 10, 60];
        }
        $token = $mutation ? bin2hex(random_bytes(16)) : '';
        $headers = $this->reserve($limits, $mutation ? $this->key('inflight', $id) : null, $token);

        return ['headers' => $headers, 'token' => $token];
    }

    public function keyOperation(int $customerId, bool $disable = false, bool $confirmation = false): void
    {
        $this->reserve([[$disable ? 'key-disable' : ($confirmation ? 'key-confirmation' : 'key-create'), (string) $customerId, $disable || $confirmation ? 30 : 3, 600]]);
    }

    public function release(CustomerApiPrincipal $principal, string $token): void
    {
        if ($token === '') {
            return;
        }
        try {
            $this->evaluate("return redis.call('ZREM', KEYS[1], ARGV[1])", [$this->key('inflight', (string) $principal->customerId)], [$token]);
        } catch (Throwable) {
            // A committed purchase is not undone by a lost admission release; TTL bounds the leak.
        }
    }

    public function evaluate(string $script, array $keys, array $arguments = []): mixed
    {
        try {
            $store = Cache::store(config('cache.limiter'))->getStore();
            if (! $store instanceof RedisStore) {
                throw CustomerApiFailure::unavailable();
            }
            $keys = array_map(fn (string $key): string => $store->getPrefix().$key, $keys);

            return $store->connection()->eval($script, count($keys), ...array_merge($keys, $arguments));
        } catch (Throwable) {
            throw CustomerApiFailure::unavailable();
        }
    }

    private function key(string $purpose, string $identity): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw CustomerApiFailure::unavailable();
        }

        return 'customer-api:'.$purpose.':'.hash_hmac('sha256', $identity, $appKey);
    }

    private function reserve(array $limits, ?string $permitKey = null, string $token = ''): array
    {
        $keys = [];
        $args = [count($limits), $token, self::PERMIT_SECONDS];
        foreach ($limits as [$purpose, $identity, $maximum, $seconds]) {
            $keys[] = $this->key($purpose, $identity);
            $args[] = $maximum;
            $args[] = $seconds;
        }
        if ($permitKey !== null) {
            $keys[] = $permitKey;
        }
        $result = $this->evaluate(<<<'LUA'
local n = tonumber(ARGV[1])
local now = tonumber(redis.call('TIME')[1])
local counts = {}
local resets = {}
local selected = 1
local lowest = 2
for i = 1,n do
    local maximum = tonumber(ARGV[2*i+2])
    local seconds = tonumber(ARGV[2*i+3])
    local count = tonumber(redis.call('GET', KEYS[i]) or '0')
    local ttl = redis.call('PTTL', KEYS[i])
    if ttl < 0 then count = 0; ttl = seconds*1000 end
    ttl = math.ceil(ttl/1000)
    if count >= maximum then return {0,maximum,0,now+math.max(1,ttl),math.max(1,ttl)} end
    counts[i] = count
    resets[i] = now+math.max(1,ttl)
    local ratio = (maximum-count-1)/maximum
    if ratio < lowest then lowest = ratio; selected = i end
end
for i = 1,n do
    redis.call('INCR', KEYS[i])
    if counts[i] == 0 then redis.call('EXPIRE', KEYS[i], tonumber(ARGV[2*i+3])) end
end
if ARGV[2] ~= '' then
    local key = KEYS[n+1]
    redis.call('ZREMRANGEBYSCORE', key, '-inf', now)
    if redis.call('ZCARD', key) >= 2 then
        local earliest = redis.call('ZRANGE', key, 0, 0, 'WITHSCORES')
        local retry = math.max(1,tonumber(earliest[2])-now)
        return {0,2,0,now+retry,retry}
    end
    redis.call('ZADD', key, now+tonumber(ARGV[3]), ARGV[2])
    redis.call('EXPIRE', key, tonumber(ARGV[3]))
end
local maximum = tonumber(ARGV[2*selected+2])
return {1,maximum,maximum-counts[selected]-1,resets[selected],0}
LUA, $keys, $args);
        $headers = ['X-RateLimit-Limit' => (string) $result[1], 'X-RateLimit-Remaining' => (string) $result[2], 'X-RateLimit-Reset' => (string) $result[3]];
        if ((int) $result[0] !== 1) {
            $headers['Retry-After'] = (string) $result[4];
            throw new CustomerApiFailure(429, 'rate_limited', 'API admission limit reached.', $headers);
        }

        return $headers;
    }
}
