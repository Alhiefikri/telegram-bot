<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Services\Pakasir\PakasirCallbackProcessor;
use App\Services\Pakasir\PakasirPayloadSanitizer;
use App\Services\Pakasir\PakasirProviderAdapter;
use App\Services\Pakasir\PakasirProviderReadiness;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcilePakasirPayments extends Command
{
    private const CURSOR_KEY = 'pakasir:reconcile:cursor';

    protected $signature = 'pakasir:reconcile {--chunk=100} {--max-payments=10} {--max-requests=30}';

    protected $description = 'Reconcile bounded pending or locally released Pakasir payments from authoritative transaction detail.';

    public function handle(PakasirProviderAdapter $client, PakasirCallbackProcessor $processor, PakasirProviderReadiness $pakasirReadiness): int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        $maxPayments = filter_var($this->option('max-payments'), FILTER_VALIDATE_INT);
        $maxRequests = filter_var($this->option('max-requests'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1000 || $maxPayments === false || $maxPayments < 1 || $maxPayments > 100 || $maxRequests === false || $maxRequests < 1 || $maxRequests > 300) {
            $this->error('pakasir:reconcile failed: chunk must be 1..1000, max-payments 1..100, max-requests 1..300.');

            return self::FAILURE;
        }
        $readiness = $pakasirReadiness->verification();
        if (! $readiness->isReady()) {
            $this->error('pakasir:reconcile failed: '.$readiness->configurationMessage());

            return self::FAILURE;
        }
        $deadline = hrtime(true) + (int) (max(1.0, (float) config('services.pakasir.reconcile_max_execution_seconds', 45)) * 1_000_000_000);

        return ProviderVerificationProfiles::until($deadline, function () use ($client, $processor, $chunk, $maxPayments): int {
            $startedAt = hrtime(true);
            $processed = $skipped = $failed = $selected = $pages = 0;
            $releasedCutoff = now()->subHours(max(1, (int) config('services.pakasir.reconcile_released_lookback_hours', 72)));
            $query = fn (): Builder => Payment::query()->where('provider', 'pakasir')->whereNotNull('merchant_ref')
                ->where(fn (Builder $query) => $query->where('status', PaymentStatus::Pending->value)
                    ->orWhere(fn (Builder $query) => $query->whereIn('status', [PaymentStatus::Expired->value, PaymentStatus::Failed->value])->where('updated_at', '>=', $releasedCutoff)));
            $cursor = Cache::get(self::CURSOR_KEY);
            // A fixed high-water mark makes a pass finite even as new UUIDs arrive.
            if (! is_array($cursor) || ! isset($cursor['end'])) {
                $cursor = ['id' => null, 'end' => $query()->orderByDesc('id')->value('id')];
            }
            $wrapped = $cursor['end'] === null;
            while (! $wrapped && $selected < $maxPayments && ProviderVerificationProfiles::stopReason() === null) {
                $pageLimit = min($chunk, $maxPayments - $selected);
                $page = $query()->where('id', '<=', $cursor['end'])
                    ->when($cursor['id'] !== null, fn (Builder $query) => $query->where('id', '>', $cursor['id']))
                    ->orderBy('id')->limit($pageLimit)->get();
                $pages++;
                foreach ($page as $payment) {
                    if (ProviderVerificationProfiles::stopReason() !== null) {
                        break;
                    }
                    $selected++;
                    $cursor['id'] = (string) $payment->getKey();
                    // Commit progress before I/O: interrupted/failed rows return on wrap,
                    // rather than starving every row behind a permanently broken row.
                    Cache::forever(self::CURSOR_KEY, $cursor);
                    try {
                        $event = $processor->processReconciliationForPayment($payment, $client->transactionDetail($payment));
                        $event->processed_at === null ? $skipped++ : $processed++;
                    } catch (Throwable $exception) {
                        $failed++;
                        $this->error('Pakasir reconciliation failed payment '.$payment->id.': '.$this->safeExceptionMessage($exception));
                    }
                }
                $wrapped = $page->isEmpty() || $cursor['id'] === $cursor['end'];
            }
            if ($wrapped) {
                Cache::forget(self::CURSOR_KEY);
            }
            $reason = ProviderVerificationProfiles::stopReason() ?? ($wrapped ? 'exhausted' : 'payment_limit');
            $stats = [
                'selected' => $selected, 'processed' => $processed, 'skipped' => $skipped, 'failed' => $failed,
                'http_attempt_count' => ProviderVerificationProfiles::requests(), 'pages' => $pages,
                'stop_reason' => $reason, 'carry_over' => ! $wrapped, 'cursor' => $cursor, 'wrapped' => $wrapped,
                'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                'peak_memory_bytes' => memory_get_peak_usage(true),
            ];
            Log::info('pakasir.reconciliation.completed', $stats);
            $this->line('pakasir:reconcile sweep: selected='.$selected.' requests='.$stats['http_attempt_count'].' stop_reason='.$reason.' carry_over='.($stats['carry_over'] ? 'yes' : 'no').' duration_ms='.$stats['duration_ms']);
            $summary = "processed={$processed} skipped={$skipped} failed={$failed}";
            if ($failed > 0) {
                $this->error('pakasir:reconcile completed with partial failures: '.$summary);

                return self::FAILURE;
            }
            if ($skipped > 0) {
                $this->warn('pakasir:reconcile completed with skips: '.$summary);
            } else {
                $this->info('pakasir:reconcile completed cleanly: '.$summary);
            }

            return self::SUCCESS;
        }, $maxRequests);
    }

    public static function schedulerMutexMinutes(): int
    {
        return max(2, (int) ceil((max(1.0, (float) config('services.pakasir.reconcile_max_execution_seconds', 45)) + 60) / 60));
    }

    private function safeExceptionMessage(Throwable $exception): string
    {
        $message = PakasirPayloadSanitizer::redactText($exception->getMessage(), [(string) config('services.pakasir.api_key', '')]);

        return trim($message) === '' ? $exception::class : $message;
    }
}
