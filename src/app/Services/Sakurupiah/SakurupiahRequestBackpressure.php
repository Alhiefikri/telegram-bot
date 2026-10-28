<?php

namespace App\Services\Sakurupiah;

use App\Enums\SakurupiahEnvironment;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class SakurupiahRequestBackpressure
{
    // Local recovery policy, not an advertised provider request-frequency limit.
    private const COOLDOWN_SECONDS = 60;

    private readonly SakurupiahEnvironment $environment;

    private int $blockedUntil = 0;

    public function __construct(?SakurupiahEnvironment $environment = null)
    {
        $this->environment = $environment ?? SakurupiahEnvironment::runtime();
    }

    public function isCoolingDown(): bool
    {
        return max($this->blockedUntil, (int) Cache::get($this->cacheKey(), 0)) > now()->getTimestamp();
    }

    public function assertAvailable(string $operation, bool $lookup): void
    {
        $previous = null;
        try {
            if (! $this->isCoolingDown()) {
                return;
            }
        } catch (Throwable $exception) {
            $previous = $exception;
        }

        if (! $lookup) {
            throw new SakurupiahCreatePreRequestException('Sakurupiah create deferred before dispatch by merchant backpressure.', $previous);
        }

        throw new SakurupiahProviderException(
            'Sakurupiah lookup deferred before dispatch by merchant backpressure.',
            reason: 'rate_limit_cooldown',
            operation: $operation,
            previous: $previous,
        );
    }

    public function observe(Response $response): void
    {
        if (! self::isRateLimited($response)) {
            return;
        }

        $retryAfter = $response->header('Retry-After');
        $retryAt = is_string($retryAfter) && ctype_digit($retryAfter) && strlen($retryAfter) <= 9
            ? now()->getTimestamp() + (int) $retryAfter
            : (is_string($retryAfter) ? strtotime($retryAfter) : false);
        $this->blockedUntil = max($this->blockedUntil, now()->getTimestamp() + self::COOLDOWN_SECONDS, $retryAt ?: 0);
        try {
            Cache::put($this->cacheKey(), $this->blockedUntil, now()->setTimestamp($this->blockedUntil));
        } catch (Throwable) {
            // Preserve the actual provider response; subsequent cache reads fail closed.
        }
    }

    public static function isRateLimited(Response $response): bool
    {
        if ($response->status() === 429) {
            return true;
        }
        if (! $response->successful() && $response->status() !== 400) {
            return false;
        }

        $payload = json_decode($response->body(), true, 32, JSON_BIGINT_AS_STRING);

        return is_array($payload)
            && in_array($payload['status'] ?? null, [400, '400', 429, '429'], true)
            && is_string($payload['message'] ?? null)
            && strtolower(trim($payload['message'])) === 'permintaan terlalu cepat silahkan coba lagi nanti.';
    }

    private function cacheKey(): string
    {
        return 'sakurupiah:backpressure:'.$this->environment->value.':'.hash('sha256',
            (string) config($this->environment->configKey('base_url')).'|'.(string) config($this->environment->configKey('api_id')),
        );
    }
}
