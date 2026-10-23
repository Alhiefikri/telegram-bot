<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Services\Bsc\BscPaymentVerification;
use App\Services\Bsc\BscVerificationOutcome;
use App\Services\OrderLifecycle;
use App\Services\Telegram\TelegramOrderExpiredNotifier;
use Illuminate\Console\Command;
use Throwable;

class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire-pending {--chunk=100}';

    protected $description = 'Expire due pending-payment orders and release reservations.';

    public function handle(OrderLifecycle $orders, TelegramOrderExpiredNotifier $notifier, BscPaymentVerification $bsc): int
    {
        $processed = 0;
        $skipped = 0;
        $failed = 0;
        $expiredThisRun = [];

        Order::where('order_status', OrderStatus::PendingPayment)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereDoesntHave('payments', fn ($query) => $query
                ->where('provider', 'manual')
                ->where('method', 'BINANCE_PAY_ID')
                ->where('status', PaymentStatus::Pending->value)
                ->where('binance_verification_mode', 'order_id')
                ->where('binance_auto_verification_enabled', true)
                ->whereNotNull('binance_verification_grace_until')
                ->where('binance_verification_grace_until', '>', now()))
            ->chunkById((int) $this->option('chunk'), function ($chunk) use ($orders, $notifier, $bsc, &$processed, &$skipped, &$failed, &$expiredThisRun): void {
                foreach ($chunk as $order) {
                    try {
                        // Inspect outside lifecycle locks: the background reconciler
                        // may not have observed an on-time transfer yet.
                        $bscPayment = $order->payments()
                            ->where('provider', 'manual')
                            ->where('method', 'USDT_BEP20')
                            ->where('status', PaymentStatus::Pending->value)
                            ->first();
                        if ($bscPayment !== null) {
                            $verification = $bsc->verify($bscPayment);
                            $order->refresh();
                            if ($order->order_status !== OrderStatus::PendingPayment
                                || in_array($verification->outcome, [
                                    BscVerificationOutcome::ProviderError,
                                    BscVerificationOutcome::NotConfigured,
                                    BscVerificationOutcome::StaleSubmission,
                                ], true)) {
                                $skipped++;

                                continue;
                            }
                        }

                        $result = $orders->applyPendingExpiry($order);
                        if ($result->order === null || ! $result->newlyApplied) {
                            $skipped++;

                            continue;
                        }

                        $expiredThisRun[] = (string) $result->order->id;
                        $notifier->send($result->order);
                        $processed++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $this->error("Failed expiring order {$order->id}: {$e->getMessage()}");
                    }
                }
            });

        $cutoff = now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)));
        Order::query()
            ->where('order_status', OrderStatus::Expired)
            ->when($expiredThisRun !== [], fn ($query) => $query->whereNotIn('id', $expiredThisRun))
            ->whereNull('telegram_expired_order_notified_at')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('telegram_expired_order_notification_started_at')
                    ->orWhere('telegram_expired_order_notification_started_at', '<=', $cutoff);
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($chunk) use ($notifier, &$processed, &$failed): void {
                foreach ($chunk as $order) {
                    try {
                        $notifier->send($order);
                        if ($order->fresh()->telegram_expired_order_notified_at !== null) {
                            $processed++;
                        }
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $this->error("Failed notifying expired order {$order->id}: {$e->getMessage()}");
                    }
                }
            });

        $this->writeSummary($processed, $skipped, $failed);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function writeSummary(int $processed, int $skipped, int $failed): void
    {
        $summary = "processed={$processed} skipped={$skipped} failed={$failed}";

        if ($failed > 0) {
            $this->error('orders:expire-pending completed with partial failures: '.$summary);

            return;
        }

        if ($skipped > 0) {
            $this->warn('orders:expire-pending completed with skips: '.$summary);

            return;
        }

        $this->info('orders:expire-pending completed cleanly: '.$summary);
    }
}
