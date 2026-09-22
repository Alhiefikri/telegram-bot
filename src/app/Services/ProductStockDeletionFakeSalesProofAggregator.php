<?php

namespace App\Services;

use App\Jobs\PublishDeletedStockFakeSalesProof;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class ProductStockDeletionFakeSalesProofAggregator
{
    public const WINDOW_SECONDS = 20;

    private const CACHE_TTL_MINUTES = 10;

    private const CACHE_PREFIX = 'fake-sales-proof:deleted-stock:';

    private const LOCK_PREFIX = self::CACHE_PREFIX.'lock:';

    /**
     * Add one committed deletion to the product's current aggregation window.
     * The caller invokes this after the stock transaction has committed.
     */
    public function record(int $productId, int $quantity = 1, ?int $soldOutMovementId = null): void
    {
        if ($productId < 1 || $quantity < 1) {
            return;
        }

        $token = null;
        $shouldDispatch = false;
        try {
            $this->withLock($productId, function () use ($productId, $quantity, $soldOutMovementId, &$token, &$shouldDispatch): void {
                $key = $this->cacheKey($productId);
                $entry = Cache::get($key);

                if (! is_array($entry) || ($entry['claimed'] ?? false) === true) {
                    $token = (string) Str::uuid();
                    $entry = [
                        'token' => $token,
                        'quantity' => 0,
                        'sold_out_movement_id' => null,
                        'claimed' => false,
                        'dispatch_pending' => true,
                    ];
                    $shouldDispatch = true;
                } else {
                    $token = (string) ($entry['token'] ?? '');
                    if ($token === '') {
                        $token = (string) Str::uuid();
                        $entry['token'] = $token;
                        $shouldDispatch = true;
                    }
                    $shouldDispatch = ($entry['dispatch_pending'] ?? true) === true;
                }

                $entry['quantity'] = max(0, (int) ($entry['quantity'] ?? 0)) + $quantity;
                if ($soldOutMovementId !== null) {
                    $entry['sold_out_movement_id'] = $soldOutMovementId;
                }

                Cache::put($key, $entry, now()->addMinutes(self::CACHE_TTL_MINUTES));
            });
        } catch (Throwable $exception) {
            // The committed stock mutation remains authoritative. A cache-lock
            // failure must not turn the post-commit handoff into a rollback.
            report($exception);

            return;
        }

        if (! $shouldDispatch || $token === null) {
            return;
        }

        try {
            PublishDeletedStockFakeSalesProof::dispatch($productId, $token, now()->toISOString())
                ->onQueue(StockNotificationDeliveryPipeline::QUEUE)
                ->delay(now()->addSeconds(self::WINDOW_SECONDS));
            $this->markDispatched($productId, $token);
        } catch (Throwable $exception) {
            // The committed stock mutation remains authoritative. A later
            // deletion can retry this cache handoff without changing the
            // current aggregation token or quantity.
            report($exception);
        }
    }

    private function markDispatched(int $productId, string $token): void
    {
        $this->withLock($productId, function () use ($productId, $token): void {
            $key = $this->cacheKey($productId);
            $entry = Cache::get($key);
            if (! is_array($entry) || (string) ($entry['token'] ?? '') !== $token) {
                return;
            }

            $entry['dispatch_pending'] = false;
            Cache::put($key, $entry, now()->addMinutes(self::CACHE_TTL_MINUTES));
        });
    }

    /**
     * Claim an exact aggregation batch. Later deletions start a new token, so
     * the current job cannot consume them or publish them twice.
     *
     * @return array{token:string,quantity:int,sold_out_movement_id:?int}|null
     */
    public function claim(int $productId, string $token): ?array
    {
        return $this->withLock($productId, function () use ($productId, $token): ?array {
            $key = $this->cacheKey($productId);
            $entry = Cache::get($key);
            if (! is_array($entry) || (string) ($entry['token'] ?? '') !== $token) {
                return null;
            }

            if (($entry['claimed'] ?? false) === true) {
                return [
                    'token' => $token,
                    'quantity' => max(0, (int) ($entry['quantity'] ?? 0)),
                    'sold_out_movement_id' => $this->positiveId($entry['sold_out_movement_id'] ?? null),
                ];
            }

            $entry['claimed'] = true;
            Cache::put($key, $entry, now()->addMinutes(self::CACHE_TTL_MINUTES));

            return [
                'token' => $token,
                'quantity' => max(0, (int) ($entry['quantity'] ?? 0)),
                'sold_out_movement_id' => $this->positiveId($entry['sold_out_movement_id'] ?? null),
            ];
        });
    }

    public function complete(int $productId, string $token): void
    {
        try {
            $this->withLock($productId, function () use ($productId, $token): void {
                $key = $this->cacheKey($productId);
                $entry = Cache::get($key);
                if (is_array($entry) && (string) ($entry['token'] ?? '') === $token) {
                    Cache::forget($key);
                }
            });
        } catch (Throwable $exception) {
            // Completion is cleanup only; never turn a delivered proof into a
            // queue failure or roll back the already committed inventory change.
            report($exception);
        }
    }

    private function cacheKey(int $productId): string
    {
        return self::CACHE_PREFIX.$productId;
    }

    private function positiveId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id === false ? null : (int) $id;
    }

    private function withLock(int $productId, callable $callback): mixed
    {
        return Cache::lock(self::LOCK_PREFIX.$productId, 5)->block(1, $callback);
    }
}
