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
        $keyColumn = $query->getModel()->getQualifiedKeyName();

        return $this->loadPage(
            $facet,
            fn (mixed $cursor): Collection => $this->afterCursor(
                (clone $query)->reorder($keyColumn), $keyColumn, $cursor, $limit,
            ),
            $limit,
        );
    }

    /**
     * Limit each disjoint family before merging so a page does not sort the
     * entire ready backlog. The cursor must apply inside every family limit.
     *
     * @param  non-empty-list<Builder<Model>>  $queries
     * @return Collection<int, Model>
     */
    public function loadUnion(string $facet, array $queries, int $limit): Collection
    {
        if ($queries === []) {
            throw new InvalidArgumentException('Recovery union requires at least one family.');
        }

        return $this->loadPage($facet, function (mixed $cursor) use ($queries, $limit): Collection {
            $union = null;
            foreach ($queries as $query) {
                $keyColumn = $query->getModel()->getQualifiedKeyName();
                $branch = (clone $query)->reorder($keyColumn)
                    ->when($cursor !== null, fn (Builder $query): Builder => $query->where($keyColumn, '>', $cursor))
                    ->limit($limit);
                if ($union === null) {
                    $union = $branch;
                } else {
                    $union->unionAll($branch);
                }
            }

            return $queries[0]->getModel()->newQuery()
                ->fromSub($union, $queries[0]->getModel()->getTable())
                ->orderBy($queries[0]->getModel()->getQualifiedKeyName())
                ->limit($limit)
                ->get();
        }, $limit);
    }

    /**
     * @param  callable(mixed): Collection<int, Model>  $load
     * @return Collection<int, Model>
     */
    private function loadPage(string $facet, callable $load, int $limit): Collection
    {
        if ($limit < 1 || preg_match('/\A[a-z0-9_-]+\z/', $facet) !== 1) {
            throw new InvalidArgumentException('Recovery page requires a safe facet name and positive limit.');
        }

        $cursorKey = 'telegram:recover-outbound:cursor:'.$facet;
        $cursor = Cache::get($cursorKey);

        $page = $load($cursor);
        if ($page->isEmpty() && $cursor !== null) {
            Cache::forget($cursorKey);
            $page = $load(null);
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
