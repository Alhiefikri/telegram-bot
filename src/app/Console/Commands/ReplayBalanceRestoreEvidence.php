<?php

namespace App\Console\Commands;

use App\Services\Operations\BalanceRestoreGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;

/**
 * Replays authoritative external evidence after a restore (#558): every
 * non-terminal USDT funding target is re-inspected, and non-terminal balance
 * deliveries present in the restored database are quarantined as `unknown`
 * (manual review) instead of ever being resent blindly.
 *
 * `--scan` additionally runs the durable BSC receiver scan so transfers that
 * arrived inside the lost window are observed again. A failed scan is recorded
 * as evidence and blocks `usdt-balance:restore-close` until it is re-run
 * successfully.
 */
final class ReplayBalanceRestoreEvidence extends Command
{
    protected $signature = 'usdt-balance:restore-replay
        {--limit=25 : Maximum unsettled funding targets re-inspected (1-100)}
        {--actor=operator : Operator identity recorded on the replay evidence}
        {--scan : Also run the durable BSC receiver scan (usdt-bsc:scan)}
        {--scan-limit=25 : Maximum enrolled receivers in the scan (1-25)}
        {--scan-requests=32 : Shared RPC budget for the scan (1-100)}
        {--dry-run : Report what the replay would do without writing evidence}
        {--json : Print the sanitized JSON result}';

    protected $description = 'Replay external evidence and quarantine ambiguous balance deliveries after a restore.';

    public function handle(BalanceRestoreGateService $gate): int
    {
        try {
            $limit = $this->boundedInt('limit', 1, 100, 25);
            $scanLimit = $this->boundedInt('scan-limit', 1, 25, 25);
            $scanRequests = $this->boundedInt('scan-requests', 1, 100, 32);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $scanEvidence = null;
        if ((bool) $this->option('scan')) {
            $exitCode = $this->call('usdt-bsc:scan', [
                '--limit' => $scanLimit,
                '--pages' => 2,
                '--requests' => $scanRequests,
                '--json' => true,
            ]);
            $decoded = json_decode(trim(Artisan::output()), true);
            $scanEvidence = [
                'status' => $exitCode === self::SUCCESS ? 'completed' : 'failed',
                'requests' => is_array($decoded) ? (int) ($decoded['requests'] ?? 0) : 0,
            ];
        }

        try {
            $result = $gate->replay(
                $limit,
                (string) ($this->option('actor') ?? ''),
                $scanEvidence,
                (bool) $this->option('dry-run'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $payload = $result->toArray();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info($result->dryRun
                ? 'Restore replay dry run; nothing was written.'
                : 'Restore replay completed and recorded as gate evidence.');
            $this->line(sprintf(
                '  settlement checked=%d settled=%d review=%d unchanged=%d failed=%d',
                $result->settlementChecked,
                $result->settlementSettled,
                $result->settlementReview,
                $result->settlementUnchanged,
                $result->settlementFailed,
            ));
            $this->line(sprintf(
                '  outbound quarantined=%d scan=%s requests=%d',
                $result->outboundQuarantined,
                $result->scanStatus,
                $result->scanRequests,
            ));
            if (! $result->dryRun) {
                $this->line('  next: run usdt-balance:reconcile, then usdt-balance:restore-close.');
            }
        }

        return $result->settlementFailed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function boundedInt(string $name, int $minimum, int $maximum, int $default): int
    {
        $value = trim((string) ($this->option($name) ?? ''));
        if ($value === '') {
            return $default;
        }

        if (preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            throw new InvalidArgumentException("The --{$name} option must be a non-negative integer.");
        }

        $integer = (int) $value;
        if ($integer < $minimum || $integer > $maximum) {
            throw new InvalidArgumentException("The --{$name} option must be between {$minimum} and {$maximum}.");
        }

        return $integer;
    }
}
