<?php

namespace App\Services;

use Illuminate\Support\Facades\Queue;

class QueueDepthInspector
{
    /** @return list<string> */
    public function operationalQueues(): array
    {
        return [
            'payments',
            'default',
            'telegram-outbound',
            'notifications',
            'telegram-broadcast',
            'telegram-stock',
            'suppliers',
            (string) config('services.binance.verification_queue', 'binance-verification'),
        ];
    }

    /** @return array{depth: int, oldest_pending_at: int|null} */
    public function runnable(string $queue): array
    {
        $connection = Queue::connection();
        $depth = method_exists($connection, 'pendingSize')
            ? (int) $connection->pendingSize($queue)
            : (int) $connection->size($queue);
        $oldestPendingAt = method_exists($connection, 'creationTimeOfOldestPendingJob')
            ? $connection->creationTimeOfOldestPendingJob($queue)
            : null;

        return [
            'depth' => $depth,
            'oldest_pending_at' => $oldestPendingAt === null ? null : (int) $oldestPendingAt,
        ];
    }

    public function total(string $queue): int
    {
        return (int) Queue::connection()->size($queue);
    }
}
