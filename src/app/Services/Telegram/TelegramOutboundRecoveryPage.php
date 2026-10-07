<?php

namespace App\Services\Telegram;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

final class TelegramOutboundRecoveryPage
{
    private const CURSOR_TTL_SECONDS = 86_400;

    /**
     * Load one bounded keyset page. A volatile cursor is only a fairness hint;
     * losing it after a Redis restart cannot lose or authorize an effect.
     *
     * @param  Builder<Model>  $query
     * @return Collection<int, Model>
     */
    public function load(string $facet, Builder $query, int $limit): Collection
    {
        if ($limit < 1 || preg_match('/\A[a-z0-9_-]+\z/', $facet) !== 1) {
            throw new InvalidArgumentException('Recovery page requires a safe facet name and positive limit.');
        }

        $cursorKey = 'telegram:recover-outbound:cursor:'.$facet;
        $cursor = Cache::get($cursorKey);
        $keyColumn = $query->getModel()->getQualifiedKeyName();
        $baseQuery = static fn (): Builder => (clone $query)->reorder($keyColumn);

        $page = $this->afterCursor($baseQuery(), $keyColumn, $cursor, $limit);
        if ($page->isEmpty() && $cursor !== null) {
            Cache::forget($cursorKey);
            $page = $this->afterCursor($baseQuery(), $keyColumn, null, $limit);
        }

        if ($page->count() < $limit) {
            Cache::forget($cursorKey);
        } elseif ($page->isNotEmpty()) {
            Cache::put($cursorKey, (string) $page->last()->getKey(), self::CURSOR_TTL_SECONDS);
        }

        return $page;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Collection<int, Model>
     */
    private function afterCursor(Builder $query, string $keyColumn, mixed $cursor, int $limit): Collection
    {
        return $query
            ->when($cursor !== null, fn (Builder $query): Builder => $query->where($keyColumn, '>', $cursor))
            ->limit($limit)
            ->get();
    }
}
