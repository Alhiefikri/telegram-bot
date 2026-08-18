<?php

namespace App\Services\Telegram;

use App\Enums\TelegramOutboundEffectState;
use App\Enums\TelegramOutboundEffectType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ProductPriceChangeEvent;
use App\Models\ProductPriceChangeNotificationRecipient;
use App\Models\SalesProofPost;
use App\Models\StockNotificationRecipient;
use App\Models\TelegramBroadcastRecipient;
use Illuminate\Database\Query\Builder;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class TelegramOutboundEffectContractReport
{
    /** @var array<string, list<string>> */
    public const LEGACY_CLAIM_COLUMNS = [
        'orders' => [
            'telegram_receipt_send_started_at',
            'manual_checkout_customer_notification_started_at',
            'manual_checkout_admin_notification_started_at',
            'admin_pending_order_notification_started_at',
            'admin_paid_order_notification_started_at',
            'admin_expired_order_notification_started_at',
        ],
        'payments' => [
            'payment_proof_forwarding_started_at',
            'payment_proof_forwarding_token',
        ],
        'inventory_movements' => [
            'stock_notification_started_at',
            'stock_notification_claim_token',
        ],
        'stock_notification_recipients' => [
            'claim_expires_at',
            'claim_token',
        ],
        'telegram_broadcast_recipients' => [
            'send_started_at',
            'send_claim_token',
        ],
        'product_price_change_notification_recipients' => [
            'send_started_at',
            'send_claim_token',
        ],
        'product_price_change_events' => [
            'store_channel_send_started_at',
            'store_channel_send_claim_token',
        ],
    ];

    /** @var array<string, string> */
    public const LEGACY_INDEXES = [
        'orders_recover_receipt_idx' => 'orders',
        'orders_recover_admin_pending_idx' => 'orders',
        'orders_recover_admin_paid_idx' => 'orders',
        'orders_recover_admin_expired_idx' => 'orders',
        'orders_recover_manual_notify_idx' => 'orders',
        'payments_proof_forwarding_index' => 'payments',
        'stock_notification_recipients_claim_expires_at_index' => 'stock_notification_recipients',
        'stock_notification_delivery_scan' => 'stock_notification_recipients',
        'telegram_broadcast_recipients_send_started_at_index' => 'telegram_broadcast_recipients',
        'telegram_broadcast_delivery_scan_index' => 'telegram_broadcast_recipients',
        'product_price_change_notification_recipients_send_started_at_index' => 'product_price_change_notification_recipients',
        'price_change_delivery_scan_index' => 'product_price_change_notification_recipients',
        'product_price_change_events_store_channel_send_started_at_index' => 'product_price_change_events',
    ];

    /** @return array<string, mixed> */
    public function report(): array
    {
        $legacyColumns = $this->legacyColumnsPresent();
        $legacyIndexes = $this->legacyIndexesPresent();
        $legacyEvidenceRows = $this->legacyEvidenceRows();
        $ledgerAvailable = Schema::hasTable('telegram_outbound_effects');
        $missingEffects = $ledgerAvailable ? $this->missingEffectCounts() : ['ledger_table' => 1];
        $unsafeEffects = $ledgerAvailable ? $this->unsafeEffectCounts() : [];
        $sourceReferences = $this->sourceReferences();
        $unresolvedTotal = array_sum($missingEffects) + array_sum($unsafeEffects) + count($sourceReferences);
        $preflightReady = $ledgerAvailable && $unresolvedTotal === 0;
        $contractApplied = array_sum(array_map('count', $legacyColumns)) === 0
            && count($legacyIndexes) === 0;

        return [
            'generated_at' => now()->utc()->toIso8601String(),
            'contract_applied' => $contractApplied,
            'preflight_ready' => $preflightReady,
            'contract_ready' => $contractApplied && $preflightReady,
            'unresolved_total' => $unresolvedTotal,
            'legacy_columns_present' => $legacyColumns,
            'legacy_indexes_present' => $legacyIndexes,
            'legacy_evidence_rows' => $legacyEvidenceRows,
            'missing_effects' => $missingEffects,
            'unsafe_effects' => $unsafeEffects,
            'source_code_references' => $sourceReferences,
        ];
    }

    public function preflightReady(): bool
    {
        return (bool) $this->report()['preflight_ready'];
    }

    /** @return array<string, list<string>> */
    private function legacyColumnsPresent(): array
    {
        $present = [];

        foreach (self::LEGACY_CLAIM_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $tableColumns = array_values(array_filter(
                $columns,
                fn (string $column): bool => Schema::hasColumn($table, $column),
            ));
            if ($tableColumns !== []) {
                $present[$table] = $tableColumns;
            }
        }

        return $present;
    }

    /** @return array<string, list<string>> */
    private function legacyIndexesPresent(): array
    {
        $present = [];

        foreach (self::LEGACY_INDEXES as $name => $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $hasIndex = collect(Schema::getIndexes($table))
                ->contains(fn (array $index): bool => ($index['name'] ?? null) === $name);
            if ($hasIndex) {
                $present[$table][] = $name;
            }
        }

        return $present;
    }

    /** @return array<string, int> */
    private function legacyEvidenceRows(): array
    {
        $counts = [];

        $counts['orders'] = $this->evidenceCount('orders', [
            'telegram_receipt_send_started_at',
            'telegram_receipt_sent_at',
            'manual_checkout_customer_notification_started_at',
            'manual_checkout_customer_notified_at',
            'manual_checkout_admin_notification_started_at',
            'manual_checkout_admin_notified_at',
            'admin_pending_order_notification_started_at',
            'admin_pending_order_notified_at',
            'admin_paid_order_notification_started_at',
            'admin_paid_order_notified_at',
            'admin_expired_order_notification_started_at',
            'admin_expired_order_notified_at',
        ]);
        $counts['payments'] = $this->evidenceCount('payments', [
            'payment_proof_forwarding_started_at',
            'payment_proof_forwarding_token',
            'payment_proof_forwarded_at',
        ]);
        $counts['stock_notification_recipients'] = $this->evidenceCount(
            'stock_notification_recipients',
            ['claim_expires_at', 'claim_token', 'sent_at', 'failed_at', 'terminal_failed_at'],
            'attempts',
        );
        $counts['telegram_broadcast_recipients'] = $this->evidenceCount(
            'telegram_broadcast_recipients',
            ['send_started_at', 'send_claim_token', 'sent_at', 'failed_at', 'terminal_failed_at'],
            'attempts',
        );
        $counts['product_price_change_notification_recipients'] = $this->evidenceCount(
            'product_price_change_notification_recipients',
            ['send_started_at', 'send_claim_token', 'sent_at', 'failed_at'],
            'attempts',
        );
        $counts['product_price_change_events'] = $this->evidenceCount(
            'product_price_change_events',
            ['store_channel_send_started_at', 'store_channel_send_claim_token', 'store_channel_sent_at', 'store_channel_failed_at'],
            'store_channel_attempts',
        );
        $counts['inventory_movements'] = $this->evidenceCount('inventory_movements', [
            'stock_notification_started_at',
            'stock_notification_claim_token',
            'stock_notification_sent_at',
        ]);
        $counts['sales_proof_posts'] = Schema::hasTable('sales_proof_posts')
            ? DB::table('sales_proof_posts')->count()
            : 0;

        return $counts;
    }

    /** @return array<string, int> */
    private function missingEffectCounts(): array
    {
        return [
            'checkout_or_order_notifications' => $this->missingDirectEffects(
                'orders',
                [
                    'telegram_receipt_send_started_at',
                    'telegram_receipt_sent_at',
                    'manual_checkout_customer_notification_started_at',
                    'manual_checkout_customer_notified_at',
                    'manual_checkout_admin_notification_started_at',
                    'manual_checkout_admin_notified_at',
                    'admin_pending_order_notification_started_at',
                    'admin_pending_order_notified_at',
                    'admin_paid_order_notification_started_at',
                    'admin_paid_order_notified_at',
                    'admin_expired_order_notification_started_at',
                    'admin_expired_order_notified_at',
                ],
                Order::class,
                [
                    TelegramOutboundEffectType::ManualCheckoutInstructions->value,
                    TelegramOutboundEffectType::KlikQrisCheckoutInstructions->value,
                    TelegramOutboundEffectType::PakasirCheckoutInstructions->value,
                    TelegramOutboundEffectType::BinanceCheckoutInstructions->value,
                    TelegramOutboundEffectType::ReceiptCredentials->value,
                    TelegramOutboundEffectType::AdminOrderPendingNotification->value,
                    TelegramOutboundEffectType::AdminOrderPaidNotification->value,
                    TelegramOutboundEffectType::AdminOrderExpiredNotification->value,
                ],
            ),
            'manual_payment_proofs' => $this->missingDirectEffects(
                'payments',
                ['payment_proof_forwarding_started_at', 'payment_proof_forwarding_token', 'payment_proof_forwarded_at'],
                Payment::class,
                [TelegramOutboundEffectType::ManualPaymentProof->value],
            ),
            'stock_recipients' => $this->missingDirectEffects(
                'stock_notification_recipients',
                ['claim_expires_at', 'claim_token', 'sent_at', 'failed_at', 'terminal_failed_at'],
                StockNotificationRecipient::class,
                [TelegramOutboundEffectType::StockNotificationRecipient->value],
                'attempts',
            ),
            'broadcast_recipients' => $this->missingDirectEffects(
                'telegram_broadcast_recipients',
                ['send_started_at', 'send_claim_token', 'sent_at', 'failed_at', 'terminal_failed_at'],
                TelegramBroadcastRecipient::class,
                [TelegramOutboundEffectType::AdminBroadcastRecipient->value],
                'attempts',
            ),
            'price_recipients' => $this->missingDirectEffects(
                'product_price_change_notification_recipients',
                ['send_started_at', 'send_claim_token', 'sent_at', 'failed_at'],
                ProductPriceChangeNotificationRecipient::class,
                [TelegramOutboundEffectType::PriceChangeNotificationRecipient->value],
                'attempts',
            ),
            'price_store_channel' => $this->missingDirectEffects(
                'product_price_change_events',
                ['store_channel_send_started_at', 'store_channel_send_claim_token', 'store_channel_sent_at', 'store_channel_failed_at'],
                ProductPriceChangeEvent::class,
                [TelegramOutboundEffectType::PriceChangeStoreChannel->value],
                'store_channel_attempts',
            ),
            'sales_proof_posts' => $this->missingSalesProofEffects(),
            'stock_movement_effects' => $this->missingStockMovementEffects(),
        ];
    }

    /** @return array<string, int> */
    private function unsafeEffectCounts(): array
    {
        return [
            'checkout_or_order_notifications' => $this->unsafeDirectEffects(
                'orders',
                [
                    'telegram_receipt_send_started_at',
                    'manual_checkout_customer_notification_started_at',
                    'manual_checkout_admin_notification_started_at',
                    'admin_pending_order_notification_started_at',
                    'admin_paid_order_notification_started_at',
                    'admin_expired_order_notification_started_at',
                ],
                Order::class,
                [
                    TelegramOutboundEffectType::ManualCheckoutInstructions->value,
                    TelegramOutboundEffectType::KlikQrisCheckoutInstructions->value,
                    TelegramOutboundEffectType::PakasirCheckoutInstructions->value,
                    TelegramOutboundEffectType::BinanceCheckoutInstructions->value,
                    TelegramOutboundEffectType::ReceiptCredentials->value,
                    TelegramOutboundEffectType::AdminOrderPendingNotification->value,
                    TelegramOutboundEffectType::AdminOrderPaidNotification->value,
                    TelegramOutboundEffectType::AdminOrderExpiredNotification->value,
                ],
            ),
            'manual_payment_proofs' => $this->unsafeDirectEffects(
                'payments',
                ['payment_proof_forwarding_started_at', 'payment_proof_forwarding_token'],
                Payment::class,
                [TelegramOutboundEffectType::ManualPaymentProof->value],
            ),
            'stock_recipients' => $this->unsafeDirectEffects(
                'stock_notification_recipients',
                ['claim_expires_at', 'claim_token'],
                StockNotificationRecipient::class,
                [TelegramOutboundEffectType::StockNotificationRecipient->value],
            ),
            'broadcast_recipients' => $this->unsafeDirectEffects(
                'telegram_broadcast_recipients',
                ['send_started_at', 'send_claim_token'],
                TelegramBroadcastRecipient::class,
                [TelegramOutboundEffectType::AdminBroadcastRecipient->value],
            ),
            'price_recipients' => $this->unsafeDirectEffects(
                'product_price_change_notification_recipients',
                ['send_started_at', 'send_claim_token'],
                ProductPriceChangeNotificationRecipient::class,
                [TelegramOutboundEffectType::PriceChangeNotificationRecipient->value],
            ),
            'price_store_channel' => $this->unsafeDirectEffects(
                'product_price_change_events',
                ['store_channel_send_started_at', 'store_channel_send_claim_token'],
                ProductPriceChangeEvent::class,
                [TelegramOutboundEffectType::PriceChangeStoreChannel->value],
            ),
            'sales_proof_posts' => 0,
            'stock_movement_effects' => 0,
        ];
    }

    /** @param list<string> $columns */
    private function evidenceCount(string $table, array $columns, ?string $attemptsColumn = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $available = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
        if ($available === [] && ($attemptsColumn === null || ! Schema::hasColumn($table, $attemptsColumn))) {
            return 0;
        }

        return (int) DB::table($table)->where(function (Builder $query) use ($available, $attemptsColumn): void {
            foreach ($available as $column) {
                $query->orWhereNotNull($column);
            }
            if ($attemptsColumn !== null) {
                $query->orWhere($attemptsColumn, '>', 0);
            }
        })->count();
    }

    /** @param list<string> $evidenceColumns @param list<string> $effectTypes */
    private function missingDirectEffects(
        string $table,
        array $evidenceColumns,
        string $sourceType,
        array $effectTypes,
        ?string $attemptsColumn = null,
    ): int {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $available = array_values(array_filter($evidenceColumns, fn (string $column): bool => Schema::hasColumn($table, $column)));
        if ($available === [] && ($attemptsColumn === null || ! Schema::hasColumn($table, $attemptsColumn))) {
            return 0;
        }

        return (int) DB::table($table.' as source')
            ->where(fn (Builder $evidence) => $this->whereEvidence($evidence, $available, $attemptsColumn, 'source.'))
            ->whereNotExists(function (Builder $effects) use ($sourceType, $effectTypes): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects as effect')
                    ->where('effect.source_type', $sourceType)
                    ->whereIn('effect.effect_type', $effectTypes)
                    ->whereRaw('effect.source_id = CAST(source.id AS TEXT)');
            })
            ->count();
    }

    /** @param list<string> $evidenceColumns @param list<string> $effectTypes */
    private function unsafeDirectEffects(
        string $table,
        array $evidenceColumns,
        string $sourceType,
        array $effectTypes,
    ): int {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        $available = array_values(array_filter($evidenceColumns, fn (string $column): bool => Schema::hasColumn($table, $column)));
        if ($available === []) {
            return 0;
        }

        return (int) DB::table($table.' as source')
            ->where(fn (Builder $evidence) => $this->whereEvidence($evidence, $available, null, 'source.'))
            ->whereExists(function (Builder $effects) use ($sourceType, $effectTypes): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects as effect')
                    ->where('effect.source_type', $sourceType)
                    ->whereIn('effect.effect_type', $effectTypes)
                    ->whereIn('effect.state', [
                        TelegramOutboundEffectState::Pending->value,
                        TelegramOutboundEffectState::Sending->value,
                    ])
                    ->whereRaw('effect.source_id = CAST(source.id AS TEXT)');
            })
            ->count();
    }

    /** @param list<string> $columns */
    private function whereEvidence(Builder $query, array $columns, ?string $attemptsColumn, string $prefix = ''): void
    {
        foreach ($columns as $column) {
            $query->orWhereNotNull($prefix.$column);
        }
        if ($attemptsColumn !== null) {
            $query->orWhere($prefix.$attemptsColumn, '>', 0);
        }
    }

    private function missingSalesProofEffects(): int
    {
        if (! Schema::hasTable('sales_proof_posts')) {
            return 0;
        }

        return (int) DB::table('sales_proof_posts as source')
            ->whereNotExists(function (Builder $effects): void {
                $effects
                    ->selectRaw('1')
                    ->from('telegram_outbound_effects as effect')
                    ->where('effect.source_type', Order::class)
                    ->where('effect.effect_type', TelegramOutboundEffectType::SalesProofPost->value)
                    ->whereRaw('effect.source_id = CAST(source.order_id AS TEXT)');
            })
            ->count();
    }

    private function missingStockMovementEffects(): int
    {
        if (! Schema::hasTable('inventory_movements') || ! Schema::hasTable('stock_notification_events')) {
            return 0;
        }

        $evidenceColumns = array_values(array_filter(
            ['stock_notification_started_at', 'stock_notification_claim_token', 'stock_notification_sent_at'],
            fn (string $column): bool => Schema::hasColumn('inventory_movements', $column),
        ));
        if ($evidenceColumns === []) {
            return 0;
        }

        return (int) DB::table('inventory_movements as movement')
            ->where(function (Builder $evidence) use ($evidenceColumns): void {
                foreach ($evidenceColumns as $column) {
                    $evidence->orWhereNotNull('movement.'.$column);
                }
            })
            ->whereExists(function (Builder $recipients): void {
                $recipients
                    ->selectRaw('1')
                    ->from('stock_notification_events as event')
                    ->join('stock_notification_recipients as recipient', 'recipient.stock_notification_event_id', '=', 'event.id')
                    ->whereColumn('event.inventory_movement_id', 'movement.id')
                    ->whereNotExists(function (Builder $effects): void {
                        $effects
                            ->selectRaw('1')
                            ->from('telegram_outbound_effects as effect')
                            ->where('effect.source_type', StockNotificationRecipient::class)
                            ->where('effect.effect_type', TelegramOutboundEffectType::StockNotificationRecipient->value)
                            ->whereRaw('effect.source_id = CAST(recipient.id AS TEXT)');
                    });
            })
            ->count();
    }

    /** @return list<array{file:string,column:string}> */
    private function sourceReferences(): array
    {
        $filesystem = new Filesystem;
        $reportPath = realpath(__FILE__);
        $references = [];

        foreach ($filesystem->allFiles(base_path('app')) as $file) {
            if ($reportPath !== false && realpath($file->getPathname()) === $reportPath) {
                continue;
            }

            $contents = $filesystem->get($file->getPathname());
            foreach (self::LEGACY_CLAIM_COLUMNS as $columns) {
                foreach ($columns as $column) {
                    // `claim_token` remains a field on the authoritative
                    // ledger row. Only scan the legacy model-specific claim
                    // columns; the generic name would flag `$effect->claim_token`.
                    if ($column === 'claim_token') {
                        continue;
                    }
                    if (str_contains($contents, $column)) {
                        $references[] = [
                            'file' => $file->getRelativePathname(),
                            'column' => $column,
                        ];
                    }
                }
            }
        }

        return $references;
    }
}
