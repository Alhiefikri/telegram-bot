<?php

namespace App\Console\Commands;

use App\Enums\PaymentEventType;
use App\Enums\PaymentStatus;
use App\Enums\SakurupiahEnvironment;
use App\Jobs\PollActiveSakurupiahPayment;
use App\Models\Payment;
use App\Services\ProviderVerification\ProviderVerificationProfiles;
use App\Services\Sakurupiah\SakurupiahCallbackProcessor;
use App\Services\Sakurupiah\SakurupiahInvoiceLifecycleService;
use App\Services\Sakurupiah\SakurupiahProviderLookupBudget;
use App\Services\Sakurupiah\SakurupiahProviderReadiness;
use App\Services\Sakurupiah\SakurupiahReconciliationOutcome;
use App\Services\Sakurupiah\SakurupiahReconciliationQuery;
use App\Services\Sakurupiah\SakurupiahRequestBackpressure;
use App\Services\Sakurupiah\SakurupiahTransactionStatus;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ReconcileSakurupiahPayments extends Command
{
    private const CURSOR_TTL_SECONDS = 86_400;

    private const ACTIVE_PENDING_RECHECK_MINUTES = 1;

    protected $signature = 'sakurupiah:reconcile {--chunk=100}';

    protected $description = 'Reconcile bounded eligible Sakurupiah payments from authoritative transaction status.';

    public function handle(
        SakurupiahCallbackProcessor $processor,
        SakurupiahProviderReadiness $readiness,
        SakurupiahReconciliationQuery $reconciliationQuery,
        SakurupiahInvoiceLifecycleService $lifecycle,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
    ): int {
        return $this->reconcile(
            $processor, $readiness, $reconciliationQuery, $lifecycle, $outboundEffects, $outboundEffectDispatcher,
        );
    }

    private function reconcile(
        SakurupiahCallbackProcessor $processor,
        SakurupiahProviderReadiness $readiness,
        SakurupiahReconciliationQuery $reconciliationQuery,
        SakurupiahInvoiceLifecycleService $lifecycle,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
    ): int {
        $chunk = $this->validatedChunk();
        if ($chunk === null) {
            $this->error('sakurupiah:reconcile failed: chunk must be between 1 and 1000.');

            return self::FAILURE;
        }

        $environment = SakurupiahEnvironment::runtime();
        $report = $readiness->configurationReport($environment);
        if (! $report->enabled()) {
            $this->info('sakurupiah:reconcile skipped safely: Sakurupiah is disabled for this environment.');

            return self::SUCCESS;
        }

        if (! $report->isReady()) {
            $this->error('sakurupiah:reconcile failed: the active Sakurupiah environment is not configured and ready.');

            return self::FAILURE;
        }

        $deadline = $this->executionDeadline();
        $carryOver = false;
        $requests = 0;
        $stopReasons = [];
        $processed = [];
        $budgetExhausted = false;
        $paid = 0;
        $expired = 0;
        $pending = 0;
        $verificationPending = 0;
        $invalid = 0;
        $busy = 0;
        $failed = 0;
        $recovered = 0;
        $backpressure = new SakurupiahRequestBackpressure($environment);
        $rateLimited = false;

        try {
            foreach (['verification', 'active'] as $lane) {
                if ($backpressure->isCoolingDown()) {
                    $rateLimited = true;
                    $carryOver = true;
                    $stopReasons[] = 'provider_throttled';

                    break;
                }
                $cursorKey = $this->cursorKey($environment).':'.$lane;
                // Verify known payment signals first, while reserving half the
                // window for ordinary active checkout fallback.
                $laneDeadline = $lane === 'verification'
                    ? hrtime(true) + intdiv(max(0, $deadline - hrtime(true)), 2)
                    : $deadline;

                ProviderVerificationProfiles::until($laneDeadline, function () use (
                    $lane, $cursorKey, $chunk, $reconciliationQuery,
                    $lifecycle, $processor, $outboundEffects, $outboundEffectDispatcher,
                    $backpressure, &$rateLimited,
                    &$carryOver, &$requests, &$stopReasons, &$budgetExhausted, &$processed,
                    &$paid, &$expired, &$pending, &$verificationPending, &$invalid, &$busy, &$failed, &$recovered,
                ): void {
                    $cursor = $this->resumeCursor($cursorKey);
                    $stoppedEarly = false;
                    $page = $reconciliationQuery
                        ->eligible()
                        ->when($lane === 'verification', fn (Builder $query) => $query->where(
                            fn (Builder $signals) => $reconciliationQuery->orWithSuccessIndication($signals),
                        ))
                        ->when($lane === 'active', fn (Builder $query) => $query->where('status', PaymentStatus::Pending->value))
                        // Successful signals bypass the ordinary fallback cadence.
                        ->when($lane === 'active', function (Builder $query) use ($reconciliationQuery): void {
                            $query->where(function (Builder $due) use ($reconciliationQuery): void {
                                $due->whereDoesntHave('paymentEvents', function (Builder $events): void {
                                    $events->where('provider', PaymentProvider::SAKURUPIAH)
                                        ->where('event_type', PaymentEventType::Reconciliation->value)
                                        ->where('provider_status', SakurupiahTransactionStatus::Pending->value)
                                        ->where('last_received_at', '>', now()->subMinutes(self::ACTIVE_PENDING_RECHECK_MINUTES));
                                });
                                $reconciliationQuery->orWithSuccessIndication($due);
                            });
                        })
                        ->when($cursor !== null, fn (Builder $query): Builder => $this->applyCursor($query, $cursor))
                        ->when($processed !== [], fn (Builder $query): Builder => $query->whereNotIn('id', $processed))
                        ->with(['order:id,order_status,expires_at'])
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->limit($chunk)
                        ->get();

                    foreach ($page as $payment) {
                        if ($backpressure->isCoolingDown()) {
                            $rateLimited = true;
                            $stoppedEarly = true;

                            break;
                        }
                        if (ProviderVerificationProfiles::stopReason() !== null) {
                            $stoppedEarly = true;

                            break;
                        }

                        $paymentCursor = $this->cursorFor($payment);
                        $processed[] = (string) $payment->getKey();
                        $payableBefore = $lifecycle->classify($payment)->isPayable();
                        try {
                            $outcome = $processor->reconcilePayment($payment, scheduled: true);
                        } catch (Throwable $exception) {
                            $failed++;
                            $stoppedEarly = true;
                            $this->error('Sakurupiah reconciliation failed payment '.$this->redactedPaymentId($payment).': '.$exception::class);

                            break;
                        }

                        $cursor = $paymentCursor;
                        $freshPayment = $payment->fresh();
                        if ($freshPayment !== null) {
                            PollActiveSakurupiahPayment::schedule($freshPayment);
                        }
                        match ($outcome) {
                            SakurupiahReconciliationOutcome::Paid => $paid++,
                            SakurupiahReconciliationOutcome::Expired => $expired++,
                            SakurupiahReconciliationOutcome::VerificationPending => $verificationPending++,
                            SakurupiahReconciliationOutcome::Invalid => $invalid++,
                            SakurupiahReconciliationOutcome::Busy => $busy++,
                            default => $pending++,
                        };

                        if (! $payableBefore && $lifecycle->classify($payment)->isPayable()) {
                            $recovered++;
                            $this->deliverRecoveredInstructions($payment, $outboundEffects, $outboundEffectDispatcher);
                        }
                        if ($backpressure->isCoolingDown()) {
                            $rateLimited = true;
                            $stoppedEarly = true;

                            break;
                        }
                    }

                    $reason = ProviderVerificationProfiles::stopReason();
                    $budgetExhausted = $budgetExhausted || $reason !== null;
                    $requests += ProviderVerificationProfiles::requests();
                    if ($page->count() < $chunk && ! $stoppedEarly) {
                        Cache::forget($cursorKey);
                        $cursor = null;
                    } elseif ($cursor !== null) {
                        Cache::put($cursorKey, $cursor, self::CURSOR_TTL_SECONDS);
                    }
                    $carryOver = $carryOver || $stoppedEarly || $cursor !== null;
                    $stopReasons[] = $rateLimited ? 'provider_throttled' : ($reason ?? ($stoppedEarly ? 'interrupted' : ($cursor !== null ? 'page_limit' : 'exhausted')));
                });
            }
        } catch (Throwable $exception) {
            $this->error('sakurupiah:reconcile failed safely: '.$exception::class);

            return self::FAILURE;
        }

        $summary = "paid={$paid} expired={$expired} pending={$pending} verification_pending={$verificationPending} invalid={$invalid} busy={$busy} failed={$failed} recovered={$recovered} budget_exhausted=".($budgetExhausted ? '1' : '0');
        $this->line('sakurupiah:reconcile sweep: requests='.$requests.' stop_reason='.implode(',', array_unique($stopReasons)).' carry_over='.($carryOver ? 'yes' : 'no'));

        if ($failed > 0 || $rateLimited) {
            $this->error('sakurupiah:reconcile completed with partial failures: '.$summary);

            return self::FAILURE;
        }

        $this->info('sakurupiah:reconcile completed: '.$summary);

        return self::SUCCESS;
    }

    /**
     * @return array{created_at: string, id: string}|null
     */
    private function resumeCursor(string $cursorKey): ?array
    {
        $cached = Cache::get($cursorKey);
        if (! is_array($cached) || ! isset($cached['created_at'], $cached['id'])) {
            return null;
        }

        try {
            $createdAt = new DateTimeImmutable((string) $cached['created_at']);
        } catch (\Exception) {
            return null;
        }

        return [
            'created_at' => $this->cursorTimestamp($createdAt),
            'id' => (string) $cached['id'],
        ];
    }

    private function cursorKey(SakurupiahEnvironment $environment): string
    {
        return 'sakurupiah:reconcile:cursor:'.$environment->value;
    }

    /**
     * Deliver QR instructions for a Payment this sweep turned payable. The
     * recovery effect is created once per Payment, so repeated sweeps and a
     * customer refresh cannot deliver it twice. Delivery failures never fail
     * the sweep: the ledger keeps the effect for its own recovery path.
     */
    private function deliverRecoveredInstructions(
        Payment $payment,
        TelegramOutboundEffectService $outboundEffects,
        TelegramOutboundEffectDispatcher $outboundEffectDispatcher,
    ): void {
        try {
            $payment->loadMissing('order.customer');
            $order = $payment->order;
            if ($order === null) {
                return;
            }

            $effect = $outboundEffects->recordSakurupiahPresentationRecovery($order, $payment, $order->customer);
            if ($effect !== null) {
                $outboundEffectDispatcher->dispatch($effect);
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @param  array{created_at: string, id: string}  $cursor
     */
    private function applyCursor(Builder $query, array $cursor): Builder
    {
        $createdAt = $cursor['created_at'];

        if ($cursor['id'] === '') {
            return $query->where('created_at', '>', $createdAt);
        }

        return $query->where(function (Builder $query) use ($createdAt, $cursor): Builder {
            return $query
                ->where('created_at', '>', $createdAt)
                ->orWhere(function (Builder $query) use ($createdAt, $cursor): Builder {
                    return $query
                        ->where('created_at', '=', $createdAt)
                        ->where('id', '>', $cursor['id']);
                });
        });
    }

    /**
     * @return array{created_at: string, id: string}
     */
    private function cursorFor(Payment $payment): array
    {
        $createdAt = $payment->created_at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($payment->created_at)
            : new DateTimeImmutable((string) $payment->created_at);

        if ($createdAt === false) {
            $createdAt = new DateTimeImmutable((string) $payment->created_at);
        }

        return [
            'created_at' => $this->cursorTimestamp($createdAt),
            'id' => (string) $payment->getKey(),
        ];
    }

    private function cursorTimestamp(DateTimeInterface $createdAt): string
    {
        $timestamp = $createdAt->format('Y-m-d H:i:s.u');

        return str_ends_with($timestamp, '.000000')
            ? substr($timestamp, 0, -7)
            : $timestamp;
    }

    private function executionDeadline(): int
    {
        $budgetSeconds = max(
            1.0,
            (float) config('services.sakurupiah.reconcile_max_execution_seconds', 45),
        );

        return hrtime(true) + (int) ($budgetSeconds * 1_000_000_000);
    }

    public static function schedulerMutexMinutes(): int
    {
        $budgetSeconds = max(
            1.0,
            (float) config('services.sakurupiah.reconcile_max_execution_seconds', 45),
        );

        return max(
            1,
            (int) ceil(
                ($budgetSeconds + SakurupiahProviderLookupBudget::requiredLeaseSeconds()) / 60,
            ),
        );
    }

    private function validatedChunk(): ?int
    {
        $chunk = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);
        if ($chunk === false || $chunk < 1 || $chunk > 1000) {
            return null;
        }

        return $chunk;
    }

    private function redactedPaymentId(Payment $payment): string
    {
        return hash('sha256', (string) $payment->getKey());
    }
}
