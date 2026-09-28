<?php

namespace App\Console\Commands;

use App\Enums\TelegramOutboundEffectState;
use App\Jobs\DeleteBuyerBinanceCheckoutInstruction;
use App\Jobs\DeleteBuyerCheckoutMessages;
use App\Jobs\SendProductPriceChangeNotifications;
use App\Models\Order;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\SalesProofPost;
use App\Models\StoreChannelSetting;
use App\Services\Telegram\StockNotificationDeliveryPipeline;
use App\Services\Telegram\TelegramBroadcastService;
use App\Services\Telegram\TelegramOutboundEffectDispatcher;
use App\Services\Telegram\TelegramOutboundEffectLedgerProtocol;
use App\Services\Telegram\TelegramOutboundEffectRecovery;
use App\Services\Telegram\TelegramOutboundEffectService;
use App\Support\PaymentProvider;
use Illuminate\Console\Command;

class RecoverTelegramOutboundEffects extends Command
{
    protected $signature = 'telegram:recover-outbound {--chunk=100} {--queue=notifications}';

    protected $description = 'Recover Telegram outbound effects and redispatch eligible pending work.';

    public function handle(): int
    {
        $cutoff = now()->subSeconds(max(60, (int) config('services.telegram.claim_lease_seconds', 600)));
        $dispatched = 0;
        $outboundEffects = app(TelegramOutboundEffectService::class);
        $ledger = app(TelegramOutboundEffectLedgerProtocol::class);
        $dispatcher = app(TelegramOutboundEffectDispatcher::class);
        $recovery = app(TelegramOutboundEffectRecovery::class);
        $staleLeases = $recovery->recoverExpiredLeases(now());
        $recovery->reconcileCompletedReceiptProjections((int) $this->option('chunk'));
        $recovery->reconcileMissingReceiptActionPanels((int) $this->option('chunk'));
        $recovery->reconcileMissingCredentialPins((int) $this->option('chunk'));
        $recovery->reconcileSentManualPaymentProofProjections((int) $this->option('chunk'));
        $recovery->reconcileSentSalesProofProjections((int) $this->option('chunk'));
        $recovery->reconcileSentAdminBroadcastProjections((int) $this->option('chunk'));
        $recovery->reconcileSentCheckoutInstructionProjections((int) $this->option('chunk'));
        $recovery->reconcileSentAdminOrderProjections((int) $this->option('chunk'));

        $ledger->readyToDispatchQuery(now())
            ->whereIn('effect_type', [
                TelegramOutboundEffectService::TYPE_MANUAL_CHECKOUT,
                TelegramOutboundEffectService::TYPE_KLIKQRIS_CHECKOUT,
                TelegramOutboundEffectService::TYPE_SAKURUPIAH_CHECKOUT,
                TelegramOutboundEffectService::TYPE_PAKASIR_CHECKOUT,
                TelegramOutboundEffectService::TYPE_BINANCE_CHECKOUT,
                TelegramOutboundEffectService::TYPE_BSC_CHECKOUT,
                TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP,
                TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_RECEIPT,
                TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_OWNER_ALERT,
                TelegramOutboundEffectService::TYPE_BALANCE_TOP_UP_CHANNEL_PROOF,
                TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS,
                TelegramOutboundEffectService::TYPE_RECEIPT_TXT_DOWNLOAD,
                TelegramOutboundEffectService::TYPE_RECEIPT_ORDER_ACTIONS,
                TelegramOutboundEffectService::TYPE_ADMIN_PENDING,
                TelegramOutboundEffectService::TYPE_ADMIN_PAID,
                TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED,
                TelegramOutboundEffectService::TYPE_MANUAL_PAYMENT_PROOF,
                TelegramOutboundEffectService::TYPE_SALES_PROOF,
                TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION,
                TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL,
            ])
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($effects) use (&$dispatched, $dispatcher): void {
                foreach ($effects as $effect) {
                    $dispatcher->dispatch($effect, now()->toISOString());
                    $dispatched++;
                }
            });
        $dispatched += $recovery->recoverMissingStoreBalanceTopUpEffects((int) $this->option('chunk'));

        SalesProofPost::query()
            ->whereIn('status', [
                SalesProofPost::STATUS_PENDING,
                SalesProofPost::STATUS_FAILED,
                SalesProofPost::STATUS_SENDING,
                SalesProofPost::STATUS_UNKNOWN,
                SalesProofPost::STATUS_MANUAL_REVIEW,
            ])
            ->where('attempts', '<', TelegramOutboundEffectService::SALES_PROOF_MAX_ATTEMPTS)
            ->whereNotExists(function ($effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects as sales_proof_effects')
                    ->where('sales_proof_effects.source_type', Order::class)
                    ->where('sales_proof_effects.effect_type', TelegramOutboundEffectService::TYPE_SALES_PROOF)
                    ->whereRaw('sales_proof_effects.source_id = CAST(sales_proof_posts.order_id AS TEXT)');
            })
            ->with('order')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($posts) use (&$dispatched, $outboundEffects, $dispatcher): void {
                foreach ($posts as $post) {
                    if ($post->order === null) {
                        continue;
                    }

                    $effect = $outboundEffects->materializeSalesProofPostEffect($post->order);
                    if ($effect !== null
                        && in_array($effect->state, [
                            TelegramOutboundEffectState::Pending,
                            TelegramOutboundEffectState::Failed,
                        ], true)
                        && ($effect->next_attempt_at === null || $effect->next_attempt_at->lte(now()))) {
                        $dispatcher->dispatch($effect, now()->toISOString());
                        $dispatched++;
                    }
                }
            });

        $dispatched += app(StockNotificationDeliveryPipeline::class)
            ->reconcile((int) $this->option('chunk'));

        // Source discovery is ledger-only: committed lifecycle rows without
        // an effect are materialized here, while every delivery attempt still
        // goes through the shared claim/finalization protocol. The SQL guards
        // mirror the per-order conditions below so a steady-state table is not
        // rescanned row by row on every sweep.
        Order::query()
            ->with(['customer', 'payments'])
            ->where(function ($orders): void {
                $orders
                    ->where(function ($checkout): void {
                        $checkout
                            ->where('order_status', 'pending_payment')
                            ->where('payment_status', 'pending')
                            ->where(function ($expiry): void {
                                $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            })
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as checkout_discovery_effects')
                                    ->where('checkout_discovery_effects.source_type', Order::class)
                                    ->whereIn('checkout_discovery_effects.effect_type', TelegramOutboundEffectService::CHECKOUT_EFFECT_TYPES)
                                    ->whereRaw('checkout_discovery_effects.source_id = CAST(orders.id AS TEXT)');
                            });
                    })
                    ->orWhere(function ($paid): void {
                        $paid
                            ->whereIn('order_status', ['processing', 'completed'])
                            ->where('payment_status', 'paid')
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as receipt_discovery_effects')
                                    ->where('receipt_discovery_effects.source_type', Order::class)
                                    ->where('receipt_discovery_effects.effect_type', TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS)
                                    ->whereRaw('receipt_discovery_effects.source_id = CAST(orders.id AS TEXT)');
                            });
                    })
                    ->orWhere(function ($expired): void {
                        $expired
                            ->where('order_status', 'expired')
                            ->where('payment_status', 'expired')
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as expired_admin_discovery_effects')
                                    ->where('expired_admin_discovery_effects.source_type', Order::class)
                                    ->where('expired_admin_discovery_effects.effect_type', TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED)
                                    ->whereRaw('expired_admin_discovery_effects.source_id = CAST(orders.id AS TEXT)');
                            });
                    })
                    ->orWhere(function ($pendingAdmin): void {
                        $pendingAdmin
                            ->where('order_status', 'pending_payment')
                            ->where('payment_status', 'pending')
                            ->where(function ($expiry): void {
                                $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            })
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as pending_admin_discovery_effects')
                                    ->where('pending_admin_discovery_effects.source_type', Order::class)
                                    ->where('pending_admin_discovery_effects.effect_type', TelegramOutboundEffectService::TYPE_ADMIN_PENDING)
                                    ->whereRaw('pending_admin_discovery_effects.source_id = CAST(orders.id AS TEXT)');
                            });
                    })
                    ->orWhere(function ($paidAdmin): void {
                        $paidAdmin
                            ->whereIn('order_status', ['processing', 'completed'])
                            ->where('payment_status', 'paid')
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as paid_admin_discovery_effects')
                                    ->where('paid_admin_discovery_effects.source_type', Order::class)
                                    ->where('paid_admin_discovery_effects.effect_type', TelegramOutboundEffectService::TYPE_ADMIN_PAID)
                                    ->whereRaw('paid_admin_discovery_effects.source_id = CAST(orders.id AS TEXT)');
                            });
                    });
            })
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched, $outboundEffects, $dispatcher): void {
                foreach ($orders as $order) {
                    $payment = $order->payments->first(
                        fn ($candidate): bool => TelegramOutboundEffectService::typeForPayment($candidate) !== null,
                    );
                    if ($payment !== null
                        && $order->order_status->value === 'pending_payment'
                        && $order->payment_status->value === 'pending') {
                        $checkoutType = TelegramOutboundEffectService::typeForPayment($payment);
                        if ($checkoutType !== null && $outboundEffects->forOrder($order->id, $checkoutType) === null) {
                            $effect = $outboundEffects->recordCheckoutInstructions($order, $payment, $order->customer);
                            if ($effect !== null) {
                                $dispatcher->dispatch($effect, now()->toISOString());
                                $dispatched++;
                            }
                        }
                    }

                    if (in_array($order->order_status->value, ['processing', 'completed'], true)
                        && $order->payment_status->value === 'paid'
                        && $outboundEffects->forOrder($order->id, TelegramOutboundEffectService::TYPE_RECEIPT_CREDENTIALS) === null) {
                        $effect = $outboundEffects->recordReceipt($order, $order->customer);
                        if ($effect !== null) {
                            $dispatcher->dispatch($effect, now()->toISOString());
                            $dispatched++;
                        }
                    }

                    $adminType = match (true) {
                        $order->order_status->value === 'pending_payment' && $order->payment_status->value === 'pending' => TelegramOutboundEffectService::TYPE_ADMIN_PENDING,
                        in_array($order->order_status->value, ['processing', 'completed'], true) && $order->payment_status->value === 'paid' => TelegramOutboundEffectService::TYPE_ADMIN_PAID,
                        $order->order_status->value === 'expired' && $order->payment_status->value === 'expired' => TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED,
                        default => null,
                    };
                    if ($adminType !== null && $outboundEffects->forOrder($order->id, $adminType) === null) {
                        $effect = match ($adminType) {
                            TelegramOutboundEffectService::TYPE_ADMIN_PENDING => $outboundEffects->recordAdminOrderPendingNotification($order),
                            TelegramOutboundEffectService::TYPE_ADMIN_PAID => $outboundEffects->recordAdminOrderPaidNotification($order),
                            TelegramOutboundEffectService::TYPE_ADMIN_EXPIRED => $outboundEffects->recordAdminOrderExpiredNotification($order),
                            default => null,
                        };
                        if ($effect !== null) {
                            $dispatcher->dispatch($effect, now()->toISOString());
                            $dispatched++;
                        }
                    }
                }
            });

        // Cleanup eligibility must mirror the cleaner's own guards. When the
        // scan keeps selecting orders the cleaner skips, every sweep
        // rediscovers them and dispatches deletion jobs that can never do
        // anything.
        Order::query()
            ->whereIn('order_status', ['completed', 'cancelled'])
            ->where(function ($query) use ($cutoff): void {
                // The Binance-manual instruction cleaner deletes its tracked
                // message without requiring the completed receipt marker.
                $query->where(function ($binance) use ($cutoff): void {
                    $binance
                        ->whereNotNull('manual_checkout_customer_notification_chat_id')
                        ->whereNotNull('manual_checkout_customer_notification_message_id')
                        ->whereNull('manual_checkout_customer_notification_deleted_at')
                        ->where(function ($claim) use ($cutoff): void {
                            $claim->whereNull('manual_checkout_customer_notification_deletion_started_at')
                                ->orWhere('manual_checkout_customer_notification_deletion_started_at', '<=', $cutoff);
                        })
                        ->whereHas('payments', fn ($payments) => $payments
                            ->where('provider', PaymentProvider::MANUAL)
                            ->whereIn('method', ['BINANCE_PAY_ID', 'USDT_BEP20']));
                })->orWhere(function ($tracked) use ($cutoff): void {
                    $tracked
                        ->where(function ($receipt): void {
                            $receipt->where('order_status', 'cancelled')
                                ->orWhereNotNull('telegram_receipt_sent_at');
                        })
                        ->where(function ($messages) use ($cutoff): void {
                            $messages->where(function ($checkout) use ($cutoff): void {
                                $checkout
                                    ->whereNotNull('manual_checkout_customer_notification_chat_id')
                                    ->whereNotNull('manual_checkout_customer_notification_message_id')
                                    ->whereNull('manual_checkout_customer_notification_deleted_at')
                                    ->where(function ($claim) use ($cutoff): void {
                                        $claim->whereNull('manual_checkout_customer_notification_deletion_started_at')
                                            ->orWhere('manual_checkout_customer_notification_deletion_started_at', '<=', $cutoff);
                                    })
                                    ->whereHas('payments', function ($payments): void {
                                        $payments->where(function ($candidates): void {
                                            $candidates
                                                ->where(fn ($binance) => $binance
                                                    ->where('provider', PaymentProvider::MANUAL)
                                                    ->whereIn('method', ['BINANCE_PAY_ID', 'USDT_BEP20']))
                                                ->orWhere('provider', PaymentProvider::KLIKQRIS);
                                        });
                                    });
                            })->orWhere(function ($detail) use ($cutoff): void {
                                $detail
                                    ->whereNotNull('buyer_order_detail_message_chat_id')
                                    ->whereNotNull('buyer_order_detail_message_id')
                                    ->whereNull('buyer_order_detail_message_deleted_at')
                                    ->where(function ($claim) use ($cutoff): void {
                                        $claim->whereNull('buyer_order_detail_message_deletion_started_at')
                                            ->orWhere('buyer_order_detail_message_deletion_started_at', '<=', $cutoff);
                                    });
                            });
                        });
                });
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($orders) use (&$dispatched): void {
                foreach ($orders as $order) {
                    $job = $order->payments()->where('provider', PaymentProvider::MANUAL)->whereIn('method', ['BINANCE_PAY_ID', 'USDT_BEP20'])->exists()
                        ? DeleteBuyerBinanceCheckoutInstruction::dispatch($order->id, now()->toISOString())
                        : DeleteBuyerCheckoutMessages::dispatch($order->id, now()->toISOString());
                    $job
                        ->onQueue((string) $this->option('queue'));
                    $dispatched++;
                }
            });

        $storeChannelTargetConfigured = StoreChannelSetting::query()
            ->where('singleton_key', StoreChannelSetting::SINGLETON_KEY)
            ->where(function ($setting): void {
                $setting->whereNotNull('telegram_chat_id')->where('telegram_chat_id', '<>', '')
                    ->orWhere(fn ($username) => $username->whereNotNull('channel_username')->where('channel_username', '<>', ''));
            })
            ->exists();

        ProductPriceChangeEvent::query()
            ->where(function ($query) use ($storeChannelTargetConfigured): void {
                $query
                    ->whereNull('recipients_materialized_at')
                    ->orWhereHas('recipients', function ($recipients): void {
                        $recipients
                            ->whereNull('sent_at')
                            ->where('attempts', '<', TelegramOutboundEffectService::PRICE_CHANGE_MAX_ATTEMPTS)
                            ->whereNotExists(function ($effects): void {
                                $effects
                                    ->selectRaw('1')
                                    ->from('telegram_outbound_effects as price_recipient_effects')
                                    ->where('price_recipient_effects.source_type', ProductPriceChangeNotificationRecipient::class)
                                    ->where('price_recipient_effects.effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_NOTIFICATION)
                                    ->whereRaw('price_recipient_effects.source_id = CAST(product_price_change_notification_recipients.id AS TEXT)');
                            });
                    })
                    ->when($storeChannelTargetConfigured, function ($query): void {
                        $query->orWhere(function ($storeChannel): void {
                            $storeChannel
                                ->whereNull('store_channel_sent_at')
                                ->where('store_channel_attempts', '<', TelegramOutboundEffectService::PRICE_CHANGE_STORE_CHANNEL_MAX_ATTEMPTS)
                                ->whereNotExists(function ($effects): void {
                                    $effects
                                        ->selectRaw('1')
                                        ->from('telegram_outbound_effects as price_channel_effects')
                                        ->where('price_channel_effects.source_type', ProductPriceChangeEvent::class)
                                        ->where('price_channel_effects.effect_type', TelegramOutboundEffectService::TYPE_PRICE_CHANGE_STORE_CHANNEL)
                                        ->whereRaw('price_channel_effects.source_id = CAST(product_price_change_events.id AS TEXT)');
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

        $broadcastService = app(TelegramBroadcastService::class);
        $broadcastService
            ->recoverableBroadcastsQuery(now())
            ->orderBy('id')
            ->chunkById((int) $this->option('chunk'), function ($broadcasts) use (&$dispatched, $broadcastService): void {
                foreach ($broadcasts as $broadcast) {
                    $broadcastService->dispatchAdminBroadcastForRecovery($broadcast);
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
