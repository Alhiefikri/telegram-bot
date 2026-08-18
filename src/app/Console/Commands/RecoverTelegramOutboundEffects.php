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
use App\Jobs\SendPakasirCheckoutInstructions;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Jobs\SendTelegramBroadcastBatch;
use App\Models\Order;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\Telegram\ProductPriceChangeNotificationService;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;

class RecoverTelegramOutboundEffects extends Command
{
    protected $signature = 'telegram:recover-outbound {--chunk=100} {--queue=notifications}';

    protected $description = 'Recover Telegram outbound effects and redispatch eligible pending work.';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)));
        $dispatched = 0;
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $staleLeases = $outboundEffects->recoverExpiredLeases(now());

        SalesProofPost::query()
            ->whereIn('status', [
                SalesProofPost::STATUS_PENDING,
                SalesProofPost::STATUS_FAILED,
                SalesProofPost::STATUS_SENDING,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query
                    ->where('status', '<>', SalesProofPost::STATUS_SENDING)
                    ->orWhere('updated_at', '<=', $cutoff);
            })
            ->whereNotExists(function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects as sales_proof_effects')
                    ->where('sales_proof_effects.source_type', Order::class)
                    ->where('sales_proof_effects.effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
                    ->whereRaw('sales_proof_effects.source_id = CAST(sales_proof_posts.order_id AS TEXT)');
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($posts) use (&$dispatched, $outboundEffects): void {
                foreach ($posts as $post) {
                    $order = Order::find($post->order_id);
                    if ($order === null) {
                        continue;
                    }

                    $effect = $outboundEffects->materializeSalesProofPostEffect($order);
                    if ($effect !== null) {
                        $outboundEffects->dispatch($effect, now()->toISOString());
                        $dispatched++;
                    }
                }
            });

        $outboundEffects->readyToDispatchQuery(now())
            ->whereIn('effect_type', [
                TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
                TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
                TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
                TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
                TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
                TelegramOutboundEffectService::TYPE_ADMIN_PENDING,
                TelegramOutboundEffectService::TYPE_ADMIN_PAID,
                TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED,
                TelegramOutboundEffectService::TYPE_MANUAL_PAYMENT_PROOF,
                TelegramOutboundEffectService::TYPE_SALES_PROOF,
                TelegramOutboundEffectService::TYPE_STOCK_NOTIFICATION,
                TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION,
                TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL,
            ])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($effects) use (&$dispatched, $outboundEffects): void {
                foreach ($effects as $effect) {
                    $outboundEffects->dispatch($effect, now()->toISOString());
                    $dispatched++;
                }
            });

        $effects = [
            ['admin_pending_order_notification_started_at', 'admin_pending_order_notified_at', SendAdminOrderPendingNotification::class, TelegramOutboundEffectService::TYPE_ADMIN_PENDING],
            ['admin_paid_order_notification_started_at', 'admin_paid_order_notified_at', SendAdminOrderPaidNotification::class, TelegramOutboundEffectService::TYPE_ADMIN_PAID],
            ['admin_expired_order_notification_started_at', 'admin_expired_order_notified_at', SendAdminOrderExpiredNotification::class, TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED],
        ];

        foreach ($effects as [$startedAt, $sentAt, $job, $effectType]) {
            Order::query()
                ->whereNull($sentAt)
                ->where($startedAt, '<=', $cutoff)
                ->whereNotExists(function (Builder $query) use ($effectType): void {
                    $query
                        ->selectRaw('1')
                        ->from('telegram_outbound_effects as migrated_effects')
                        ->where('migrated_effects.source_type', Order::class)
                        ->where('migrated_effects.effect_type', $effectType)
                        ->whereRaw('migrated_effects.source_id = CAST(orders.id AS TEXT)');
                })
                ->orderBy('id')
                ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched, $job): void {
                    foreach ($orders as $order) {
                        $dispatch = $job::dispatch($order->id, now()->toISOString());
                        $dispatch->onQueue((string) $this->option('queue'));
                        $dispatched++;
                    }
                });
        }

        Order::query()
            ->whereNull('manual_checkout_customer_notified_at')
            ->where('manual_checkout_customer_notification_started_at', '<=', $cutoff)
            ->whereNotExists(function (Builder $query): void {
                $query
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects as migrated_effects')
                    ->where('migrated_effects.source_type', Order::class)
                    ->whereIn('migrated_effects.effect_type', [
                        TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
                        TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
                        TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
                        TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
                    ])
                    ->whereRaw('migrated_effects.source_id = CAST(orders.id AS TEXT)');
            })
            ->where('order_status', 'pending_payment')
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched): void {
                foreach ($orders as $order) {
                    $payment = $order->payments()->latest('created_at')->first();
                    if ($payment?->provider === PaymentProvider::MANUAL && $payment?->method === 'BINANCE_PAY_ID') {
                        $job = SendBinanceCheckoutInstructions::dispatch($order->id, now()->toISOString());
                    } elseif ($payment?->provider === PaymentProvider::KLIKQRIS) {
                        $job = SendKlikQrisCheckoutInstructions::dispatch($order->id, now()->toISOString());
                    } elseif ($payment?->provider === PaymentProvider::PAKASIR) {
                        $job = SendPakasirCheckoutInstructions::dispatch($order->id, now()->toISOString());
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
                    ->orWhereHas('payments', fn ($payments) => $payments->where('provider', PaymentProvider::MANUAL)->where('method', 'BINANCE_PAY_ID'));
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
                    $job = $order->payments()->where('provider', PaymentProvider::MANUAL)->where('method', 'BINANCE_PAY_ID')->exists()
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
                            ->where(function ($attempts) use ($cutoff): void {
                                $attempts
                                    ->where('attempts', '<', ProductPriceChangeNotificationService::MAX_RECIPIENT_ATTEMPTS)
                                    ->orWhereNotNull('failed_at')
                                    ->orWhere(function ($staleClaim) use ($cutoff): void {
                                        $staleClaim
                                            ->whereNotNull('send_started_at')
                                            ->where('send_started_at', '<=', $cutoff);
                                    });
                            })
                            ->whereNotExists(function (Builder $effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as price_recipient_effects')
                                    ->where('price_recipient_effects.source_type', ProductPriceChangeNotificationRecipient::class)
                                    ->where('price_recipient_effects.effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION)
                                    ->whereRaw('price_recipient_effects.source_id = CAST(product_price_change_notification_recipients.id AS TEXT)');
                            })
                            ->where(function ($recipient) use ($cutoff): void {
                                $recipient->whereNull('send_started_at')
                                    ->orWhere('send_started_at', '<=', $cutoff);
                            });
                    })
                    ->when($storeChannelConfigured, function ($storeChannelQuery) use ($cutoff): void {
                        $storeChannelQuery->orWhere(function ($storeChannelEffect) use ($cutoff): void {
                            $storeChannelEffect
                                ->whereNull('store_channel_sent_at')
                                ->where(function ($attempts) use ($cutoff): void {
                                    $attempts
                                        ->where('store_channel_attempts', '<', ProductPriceChangeNotificationService::MAX_STORE_CHANNEL_ATTEMPTS)
                                        ->orWhereNotNull('store_channel_failed_at')
                                        ->orWhere(function ($staleClaim) use ($cutoff): void {
                                            $staleClaim
                                                ->whereNotNull('store_channel_send_started_at')
                                                ->where('store_channel_send_started_at', '<=', $cutoff);
                                        });
                                })
                                ->whereNotExists(function (Builder $effects): void {
                                    $effects
                                        ->selectRaw('1')
                                        ->from('telegram_outbound_effects as price_channel_effects')
                                        ->where('price_channel_effects.source_type', ProductPriceChangeEvent::class)
                                        ->where('price_channel_effects.effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL)
                                        ->whereRaw('price_channel_effects.source_id = CAST(product_price_change_events.id AS TEXT)');
                                })
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

        app(TelegramBroadcastService::class)
            ->recoverableBroadcastsQuery($cutoff, now())
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($broadcasts) use (&$dispatched): void {
                foreach ($broadcasts as $broadcast) {
                    SendTelegramBroadcastBatch::dispatch($broadcast->id, now()->toISOString())
                        ->onQueue('telegram-broadcast');
                    $dispatched++;
                }
            });

        $this->info("telegram:recover-outbound completed cleanly: dispatched={$dispatched}");

        if ($staleLeases > 0) {
            $this->info("telegram:recover-outbound stale_unknown={$staleLeases}");
        }

        return self::SUCCESS;
    }
}
