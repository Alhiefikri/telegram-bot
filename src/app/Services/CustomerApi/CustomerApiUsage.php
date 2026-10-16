<?php

namespace App\Services\CustomerApi;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class CustomerApiUsage
{
    public function __construct(private CustomerApiAdmission $admission) {}

    public function record(CustomerApiPrincipal $principal): void
    {
        try {
            $this->admission->evaluate(<<<'LUA'
local field = ARGV[1] .. ':' .. ARGV[2]
if redis.call('HEXISTS', KEYS[1], field) == 0 and redis.call('HLEN', KEYS[1]) >= 10000 then return 0 end
redis.call('HINCRBY', KEYS[1], field, 1)
redis.call('EXPIRE', KEYS[1], 600)
return 1
LUA, [$this->bucket(now()->timestamp)], [$principal->customerId, $principal->credentialId]);
        } catch (Throwable) {
            // Non-financial lower-bound telemetry is deliberately lossy and never changes an admitted outcome.
        }
    }

    /** Destructive bounded drain before DB commit: interruption can lose counts but cannot double them. */
    public function flush(int $limit = 500): int
    {
        $remaining = min(1000, max(1, $limit));
        $processed = 0;
        for ($offset = 9; $offset >= 0 && $remaining > 0; $offset--) {
            $timestamp = now()->timestamp - $offset * 60;
            $pairs = $this->admission->evaluate(<<<'LUA'
local pairs = redis.call('HRANDFIELD', KEYS[1], tonumber(ARGV[1]), 'WITHVALUES')
for i = 1,#pairs,2 do redis.call('HDEL', KEYS[1], pairs[i]) end
return pairs
LUA, [$this->bucket($timestamp)], [$remaining]);
            for ($i = 0; $i < count($pairs); $i += 2) {
                [$customerId, $credentialId] = array_map('intval', explode(':', $pairs[$i]));
                $count = (int) $pairs[$i + 1];
                // Minute start is a conservative last-use lower bound, not a fabricated precise timestamp.
                $usedAt = CarbonImmutable::createFromTimestamp($timestamp)->startOfMinute();
                DB::transaction(function () use ($customerId, $credentialId, $count, $usedAt): void {
                    DB::table('customer_api_usage')->insertOrIgnore(['customer_id' => $customerId, 'request_count' => 0]);
                    DB::table('customer_api_usage')->where('customer_id', $customerId)->increment('request_count', $count);
                    DB::table('customer_api_usage')->where('customer_id', $customerId)
                        ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $usedAt))->update(['last_used_at' => $usedAt]);
                    DB::table('customer_api_credentials')->where('id', $credentialId)->where('customer_id', $customerId)
                        ->where(fn ($query) => $query->whereNull('last_used_at')->orWhere('last_used_at', '<', $usedAt))->update(['last_used_at' => $usedAt]);
                }, 3);
                $remaining--;
                $processed++;
            }
        }

        return $processed;
    }

    private function bucket(int $timestamp): string
    {
        return 'customer-api:usage:'.intdiv($timestamp, 60);
    }
}
