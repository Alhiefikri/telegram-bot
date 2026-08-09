<?php

namespace App\Console\Commands;

use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Jobs\SendAdminOrderExpiredNotification;
use App\Jobs\SendAdminOrderPaidNotification;
use App\Jobs\SendAdminOrderPendingNotification;
use App\Jobs\SendBinanceCheckoutInstructions;
use App\Jobs\SendKlikQrisCheckoutInstructions;
use App\Jobs\SendManualCheckoutNotifications;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Jobs\SendTelegramReceipt;
use App\Models\Order;
use App\Models\ProductPriceChangeEvent;
use App\Models\StoreChannelSetting;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use Illuminate\Console\Command;

class RecoverTelegramOutboundEffects extends Command
{
    protected $signature = 'telegram:recover-outbound {--chunk=100} {--queue=notifications}';

    protected $description = 'Redispatch Telegram order effects whose delivery lease expired.';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)));
        $dispatched = 0;

        $effects = [
            ['telegram_receipt_send_started_at', 'telegram_receipt_sent_at', SendTelegramReceipt::class],
            ['admin_pending_order_notification_started_at', 'admin_pending_order_notified_at', SendAdminOrderPendingNotification::class],
            ['admin_paid_order_notification_started_at', 'admin_paid_order_notified_at', SendAdminOrderPaidNotification::class],
            ['admin_expired_order_notification_started_at', 'admin_expired_order_notified_at', SendAdminOrderExpiredNotification::class],
        ];

        foreach ($effects as [$startedAt, $sentAt, $job]) {
            Order::query()
                ->whereNull($sentAt)
                ->where($startedAt, '<=', $cutoff)
                ->orderBy('id')
                ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched, $job): void {
                    foreach ($orders as $order) {
                        $dispatch = $job === SendTelegramReceipt::class
                            ? SendTelegramReceipt::dispatch($order->id, false, now()->toISOString())
                            : $job::dispatch($order->id, now()->toISOString());
                        $dispatch->onQueue((string) $this->option('queue'));
                        $dispatched++;
                    }
                });
        }

        Order::query()
            ->whereNull('manual_checkout_customer_notified_at')
            ->where('manual_checkout_customer_notification_started_at', '<=', $cutoff)
            ->where('order_status', 'pending_payment')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched): void {
                foreach ($orders as $order) {
                    if ($order->payments()->where('provider', 'klikqris')->exists()) {
                        $job = SendKlikQrisCheckoutInstructions::dispatch($order->id, now()->toISOString());
                    } elseif ($order->payments()->where('method', 'BINANCE_PAY_ID')->exists()) {
                        $job = SendBinanceCheckoutInstructions::dispatch($order->id, now()->toISOString());
                    } else {
                        $job = SendManualCheckoutNotifications::dispatch($order->id, now()->toISOString());
                    }
                    $job->onQueue((string) $this->option('queue'));
                    $dispatched++;
                }
            });

        Order::query()
            ->where(function ($query): void {
                $query->whereNotNull('telegram_receipt_sent_at')
                    ->orWhere('order_status', 'cancelled')
                    ->orWhereHas('payments', fn ($payments) => $payments->where('method', 'BINANCE_PAY_ID'));
            })
            ->whereIn('order_status', ['completed', 'cancelled'])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($checkout) use ($cutoff): void {
                    $checkout->whereNotNull('manual_checkout_customer_notification_message_id')
                        ->whereNull('manual_checkout_customer_notification_deleted_at')
                        ->where(function ($claim) use ($cutoff): void {
                            $claim->whereNull('manual_checkout_customer_notification_deletion_started_at')
                                ->orWhere('manual_checkout_customer_notification_deletion_started_at', '<=', $cutoff);
                        });
                })->orWhere(function ($detail) use ($cutoff): void {
                    $detail->whereNotNull('buyer_order_detail_message_id')
                        ->whereNull('buyer_order_detail_message_deleted_at')
                        ->where(function ($claim) use ($cutoff): void {
                            $claim->whereNull('buyer_order_detail_message_deletion_started_at')
                                ->orWhere('buyer_order_detail_message_deletion_started_at', '<=', $cutoff);
                        });
                });
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched): void {
                foreach ($orders as $order) {
                    $job = $order->payments()->where('method', 'BINANCE_PAY_ID')->exists()
                        ? DeleteBuyerBinanceCheckoutInstruction::dispatch($order->id, now()->toISOString())
                        : DeleteBuyerCheckoutMessages::dispatch($order->id, now()->toISOString());
                    $job
                        ->onQueue((string) $this->option('queue'));
                    $dispatched++;
                }
            });

        $storeChannel = StoreChannelSetting::current();
        $storeChannelConfigured = trim((string) ($storeChannel->telegram_chat_id ?: $storeChannel->channel_username)) !== '';

        ProductPriceChangeEvent::query()
            ->where(function ($query) use ($cutoff, $storeChannelConfigured): void {
                $query->whereNull('recipients_materialized_at')
                    ->orWhereHas('recipients', function ($recipients) use ($cutoff): void {
                        $recipients
                            ->whereNull('sent_at')
                            ->where('attempts', '<', ProductPriceChangeNotificationService::MAX_RECIPIENT_ATTEMPTS)
                            ->where(function ($recipient) use ($cutoff): void {
                                $recipient->whereNull('send_started_at')
                                    ->orWhere('send_started_at', '<=', $cutoff);
                            });
                    })
                    ->when($storeChannelConfigured, function ($storeChannelQuery) use ($cutoff): void {
                        $storeChannelQuery->orWhere(function ($storeChannelEffect) use ($cutoff): void {
                            $storeChannelEffect
                                ->whereNull('store_channel_sent_at')
                                ->where('store_channel_attempts', '<', ProductPriceChangeNotificationService::MAX_STORE_CHANNEL_ATTEMPTS)
                                ->where(function ($storeChannelClaim) use ($cutoff): void {
                                    $storeChannelClaim->whereNull('store_channel_send_started_at')
                                        ->orWhere('store_channel_send_started_at', '<=', $cutoff);
                                });
                        });
                    });
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($events) use (&$dispatched): void {
                foreach ($events as $event) {
                    SendProductPriceChangeNotifications::dispatch($event->id, now()->toISOString())
                        ->onQueue('telegram-broadcast');
                    $dispatched++;
                }
            });

        $this->info("telegram:recover-outbound completed cleanly: dispatched={$dispatched}");

        return self::SUCCESS;
    }
}
