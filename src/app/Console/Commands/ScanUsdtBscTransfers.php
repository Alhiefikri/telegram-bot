<?php

namespace App\Console\Commands;

use App\Services\UsdtTopUps\BscUsdtTransferIngestion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ScanUsdtBscTransfers extends Command
{
    protected $signature = 'usdt-bsc:scan
        {--receiver= : Scan or enroll exactly one receiver ID}
        {--from-block= : Required first block when explicitly enrolling a receiver}
        {--after=0 : Keyset receiver cursor for enrolled scan discovery}
        {--limit=25 : Maximum enrolled receivers, at most 25}
        {--pages=2 : Maximum block pages per receiver, at most 10}
        {--requests=32 : Shared RPC operation budget, at most 100}
        {--json : Print a sanitized JSON report}';

    protected $description = 'Discover BSC USDT transfers using durable checkpoints, without receipt claims, settlement or funding activation.';

    public function handle(BscUsdtTransferIngestion $ingestion): int
    {
        try {
            $limit = $this->integerOption('limit', 1, 25);
            $pages = $this->integerOption('pages', 1, 10);
            $budget = $this->integerOption('requests', 1, 100);
            $after = $this->integerOption('after', 0, PHP_INT_MAX);
            $receiverOption = $this->option('receiver');
            if ($receiverOption !== null) {
                $receiver = $this->integerOption('receiver', 1, PHP_INT_MAX);
                $existing = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', $receiver)->first();
                $firstBlock = $this->option('from-block') === null
                    ? ($existing?->first_block ?? throw new InvalidArgumentException('Enrollment requires --from-block.'))
                    : $this->integerOption('from-block', 0, PHP_INT_MAX);
                $streams = [(object) ['receiver_id' => $receiver, 'first_block' => $firstBlock]];
            } else {
                if ($this->option('from-block') !== null) {
                    throw new InvalidArgumentException('--from-block requires --receiver.');
                }
                $streams = DB::table('usdt_bsc_scan_checkpoints')->where('receiver_id', '>', $after)
                    ->orderBy('receiver_id')->limit($limit)->get();
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }
        $reports = [];
        $requests = 0;
        $cursor = $after;
        $failed = false;
        foreach ($streams as $stream) {
            if ($requests >= $budget) {
                break;
            }
            $report = $ingestion->scan((int) $stream->receiver_id, (int) $stream->first_block, $pages, $budget - $requests);
            $requests += $report['requests'];
            $cursor = (int) $stream->receiver_id;
            $reports[] = ['receiver_id' => $cursor, ...$report];
            $failed = $failed || $report['status'] !== 'complete';
        }
        $result = ['reports' => $reports, 'requests' => $requests, 'next_after' => $cursor, 'funding_authorized' => false];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['Receiver', 'Status', 'Reason', 'Last block'], array_map(
                static fn (array $report): array => [$report['receiver_id'], $report['status'], $report['reason'], $report['last_block'] ?? 'none'],
                $reports,
            ));
            $this->line("requests={$requests} next_after={$cursor} funding_authorized=false");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function integerOption(string $name, int $minimum, int $maximum): int
    {
        $value = (string) $this->option($name);
        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1 || strlen($value) > strlen((string) $maximum)
            || (strlen($value) === strlen((string) $maximum) && strcmp($value, (string) $maximum) > 0)) {
            throw new InvalidArgumentException("Invalid --{$name} bound.");
        }
        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException("Invalid --{$name} bound.");
        }

        return $integer;
    }
}
