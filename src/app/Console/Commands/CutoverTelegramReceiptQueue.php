<?php

namespace App\Console\Commands;

use App\Models\TelegramOutboundEffect;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\QueueTopology;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * One-time cutover from the shared notifications queue to the dedicated
 * receipt queue. It rewrites persisted effect metadata and moves queued
 * receipt jobs; it never claims, resends or rewrites receipt content.
 *
 * Run it with the source worker stopped for that queue, then start the new
 * receipt worker. Reserved jobs are left to their owning worker and are
 * reported instead of being stolen from an in-flight delivery.
 */
class CutoverTelegramReceiptQueue extends Command
{
    protected $signature = 'telegram:receipt-queue-cutover
        {--from=notifications : Queue that currently holds receipt work}
        {--to= : Destination receipt queue; defaults to the supervised receipt queue}
        {--chunk=500 : Metadata rows and queue jobs per page (1-5000)}
        {--dry-run : Report the planned movement without changing anything}
        {--json : Print a machine-readable summary}';

    protected $description = 'Move persisted receipt effects and queued receipt jobs to the dedicated receipt queue.';

    public function handle(): int
    {
        $from = trim((string) $this->option('from'));
        $to = trim((string) $this->option('to')) ?: TelegramOutboundEffectService::RECEIPT_QUEUE;
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $dryRun = (bool) $this->option('dry-run');

        if ($from === '' || $to === '' || $from === $to) {
            $this->error('telegram:receipt-queue-cutover requires two different queue names.');

            return self::FAILURE;
        }

        if ($chunk === false || $chunk < 1 || $chunk > 5000) {
            $this->error('--chunk must be between 1 and 5000.');

            return self::FAILURE;
        }

        try {
            $summary = [
                'from' => $from,
                'to' => $to,
                'dry_run' => $dryRun,
                'effects' => $this->migrateEffects($from, $to, $chunk, $dryRun),
                'queued' => $this->migrateQueuedJobs($from, $to, $chunk, $dryRun),
            ];
        } catch (\Throwable $exception) {
            $this->error('telegram:receipt-queue-cutover failed: '.$exception::class.': '.$exception->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->renderSummary($summary);
        }

        return self::SUCCESS;
    }

    /** @return array{moved: int, matched: int} */
    private function migrateEffects(string $from, string $to, int $chunk, bool $dryRun): array
    {
        $matched = 0;

        TelegramOutboundEffect::query()
            ->whereIn('effect_type', TelegramOutboundEffectService::RECEIPT_EFFECT_TYPES)
            ->where('queue', $from)
            ->orderBy('id')
            ->chunkById($chunk, function ($effects) use (&$matched, $to, $dryRun): void {
                $matched += $effects->count();

                if (! $dryRun) {
                    TelegramOutboundEffect::query()
                        ->whereKey($effects->modelKeys())
                        ->update(['queue' => $to, 'updated_at' => now()]);
                }
            });

        return ['moved' => $dryRun ? 0 : $matched, 'matched' => $matched];
    }

    /** @return array{driver: string, pending: int, delayed: int, reserved: int, reserved_moved: bool, skipped: int} */
    private function migrateQueuedJobs(string $from, string $to, int $chunk, bool $dryRun): array
    {
        $connectionName = (string) config('queue.default');
        $connection = (array) config("queue.connections.{$connectionName}", []);
        $driver = (string) ($connection['driver'] ?? '');

        return match ($driver) {
            'redis' => $this->migrateRedisJobs($from, $to, $chunk, $dryRun, $connection),
            'database' => $this->migrateDatabaseJobs($from, $to, $chunk, $dryRun, $connection),
            default => [
                'driver' => $driver === '' ? 'unknown' : $driver,
                'pending' => 0,
                'delayed' => 0,
                'reserved' => 0,
                'reserved_moved' => false,
                'skipped' => 0,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array{driver: string, pending: int, delayed: int, reserved: int, reserved_moved: bool, skipped: int}
     */
    private function migrateRedisJobs(string $from, string $to, int $chunk, bool $dryRun, array $connection): array
    {
        $redis = Redis::connection((string) ($connection['connection'] ?? 'default'));
        $pendingKey = 'queues:'.$from;
        $delayedKey = 'queues:'.$from.':delayed';
        $reservedKey = 'queues:'.$from.':reserved';
        $targetPendingKey = 'queues:'.$to;
        $targetDelayedKey = 'queues:'.$to.':delayed';
        $targetNotifyKey = 'queues:'.$to.':notify';

        $summary = [
            'driver' => 'redis',
            'pending' => 0,
            'delayed' => 0,
            'reserved' => 0,
            'reserved_moved' => false,
            'skipped' => 0,
        ];

        foreach ((array) $redis->zrange($reservedKey, 0, -1) as $payload) {
            if (is_string($payload) && $this->isReceiptPayload($payload)) {
                $summary['reserved']++;
            }
        }

        $pending = (array) $redis->lrange($pendingKey, 0, -1);
        foreach (array_chunk($pending, $chunk) as $page) {
            foreach ($page as $payload) {
                if (! is_string($payload) || ! $this->isReceiptPayload($payload)) {
                    $summary['skipped']++;

                    continue;
                }

                if ($dryRun) {
                    $summary['pending']++;

                    continue;
                }

                $moved = (int) $redis->eval(
                    <<<'LUA'
local removed = redis.call('lrem', KEYS[1], 1, ARGV[1])
if removed == 1 then
    redis.call('rpush', KEYS[2], ARGV[1])
    redis.call('rpush', KEYS[3], 1)
    return 1
end
return 0
LUA,
                    3,
                    $pendingKey,
                    $targetPendingKey,
                    $targetNotifyKey,
                    $payload,
                );

                $summary['pending'] += $moved;
                if ($moved === 0) {
                    $summary['skipped']++;
                }
            }
        }

        $delayed = (array) $redis->zrange($delayedKey, 0, -1, ['withscores' => true]);
        foreach ($delayed as $payload => $score) {
            if (! is_string($payload) || ! $this->isReceiptPayload($payload)) {
                continue;
            }

            if ($dryRun) {
                $summary['delayed']++;

                continue;
            }

            $moved = (int) $redis->eval(
                <<<'LUA'
local removed = redis.call('zrem', KEYS[1], ARGV[1])
if removed == 1 then
    redis.call('zadd', KEYS[2], ARGV[2], ARGV[1])
    return 1
end
return 0
LUA,
                2,
                $delayedKey,
                $targetDelayedKey,
                $payload,
                (string) $score,
            );

            $summary['delayed'] += $moved;
            if ($moved === 0) {
                $summary['skipped']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array{driver: string, pending: int, delayed: int, reserved: int, reserved_moved: bool, skipped: int}
     */
    private function migrateDatabaseJobs(string $from, string $to, int $chunk, bool $dryRun, array $connection): array
    {
        $database = DB::connection($connection['connection'] ?? null);
        $table = (string) ($connection['table'] ?? 'jobs');
        $summary = ['driver' => 'database', 'pending' => 0, 'delayed' => 0, 'reserved' => 0, 'reserved_moved' => true, 'skipped' => 0];

        $database->table($table)
            ->where('queue', $from)
            ->orderBy('id')
            ->chunkById($chunk, function ($jobs) use ($database, $table, $to, $dryRun, &$summary): void {
                $ids = [];
                foreach ($jobs as $job) {
                    if (! $this->isReceiptPayload((string) $job->payload)) {
                        $summary['skipped']++;

                        continue;
                    }

                    $ids[] = $job->id;
                    if ($job->reserved_at !== null) {
                        $summary['reserved']++;
                    } elseif ((int) $job->available_at > now()->timestamp) {
                        $summary['delayed']++;
                    } else {
                        $summary['pending']++;
                    }
                }

                if (! $dryRun && $ids !== []) {
                    $database->table($table)->whereIn('id', $ids)->update(['queue' => $to]);
                }
            });

        return $summary;
    }

    private function isReceiptPayload(string $payload): bool
    {
        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        $displayName = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;
        if (! is_string($displayName)) {
            return false;
        }

        return in_array(ltrim($displayName, '\\'), TelegramOutboundEffectService::RECEIPT_JOB_CLASSES, true);
    }

    /** @param array<string, mixed> $summary */
    private function renderSummary(array $summary): void
    {
        $verb = $summary['dry_run'] ? 'would move' : 'moved';
        $effectsMoved = $summary['dry_run']
            ? $summary['effects']['matched']
            : $summary['effects']['moved'];
        $this->info(sprintf(
            'Receipt effects: %s %d of %d matching rows (%s -> %s).',
            $verb,
            $effectsMoved,
            $summary['effects']['matched'],
            $summary['from'],
            $summary['to'],
        ));

        $queued = $summary['queued'];
        $this->info(sprintf(
            'Queued receipt jobs on %s: %s %d pending, %d delayed; %d reserved left with their owner; %d skipped.',
            $queued['driver'],
            $verb,
            $queued['pending'],
            $queued['delayed'],
            $queued['reserved'],
            $queued['skipped'],
        ));

        if ($queued['reserved'] > 0 && ! ($queued['reserved_moved'] ?? false)) {
            $this->warn('Reserved jobs were not stolen from in-flight workers; let the source worker finish or wait for retry_after before retiring it.');
        }

        if (in_array($queued['driver'], ['sync', 'null', 'unknown'], true)) {
            $this->warn('The configured queue driver has no inspectable pending store; only persisted effects were normalized.');
        }

        $this->line('Next: start the receipt worker on '.QueueTopology::RECEIPTS.' and keep the notifications worker for email, admin, proof and cleanup work.');
    }
}
