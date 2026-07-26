<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Partial indexes for the once-a-minute recovery schedulers. RecoverTelegramUpdates
 * and RecoverTelegramOutboundEffects run every minute and scan telegram_updates and
 * orders for the small set of in-flight effects whose delivery lease expired. Without
 * these indexes each scan sequentially scans the whole table every minute.
 *
 * Every predicate mirrors the matching recovery query's WHERE clause, so PostgreSQL
 * keeps only the not-yet-finished rows in each index. Finished rows (the overwhelming
 * majority) are excluded, which keeps the indexes tiny and adds no write overhead to
 * the common path where an order's effect has already been delivered. A query such as
 * `WHERE {sent_at} IS NULL AND {started_at} <= :cutoff` still matches because
 * `{started_at} <= :cutoff` implies `{started_at} IS NOT NULL`.
 *
 * PostgreSQL only: the Laravel schema builder cannot express partial predicates, and
 * the SQLite test database keeps these tables small enough that the scans are cheap.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{table: string, predicate: string}>
     */
    private array $indexes = [
        'telegram_updates_recovery_idx' => [
            'table' => 'telegram_updates',
            'predicate' => 'processed_at IS NULL AND terminal_failed_at IS NULL',
        ],
        'orders_recover_receipt_idx' => [
            'table' => 'orders',
            'predicate' => 'telegram_receipt_sent_at IS NULL AND telegram_receipt_send_started_at IS NOT NULL',
        ],
        'orders_recover_admin_pending_idx' => [
            'table' => 'orders',
            'predicate' => 'admin_pending_order_notified_at IS NULL AND admin_pending_order_notification_started_at IS NOT NULL',
        ],
        'orders_recover_admin_paid_idx' => [
            'table' => 'orders',
            'predicate' => 'admin_paid_order_notified_at IS NULL AND admin_paid_order_notification_started_at IS NOT NULL',
        ],
        'orders_recover_admin_expired_idx' => [
            'table' => 'orders',
            'predicate' => 'admin_expired_order_notified_at IS NULL AND admin_expired_order_notification_started_at IS NOT NULL',
        ],
        'orders_recover_manual_notify_idx' => [
            'table' => 'orders',
            'predicate' => 'manual_checkout_customer_notified_at IS NULL AND manual_checkout_customer_notification_started_at IS NOT NULL',
        ],
        'orders_recover_binance_delete_idx' => [
            'table' => 'orders',
            'predicate' => 'manual_checkout_customer_notification_message_id IS NOT NULL AND manual_checkout_customer_notification_deleted_at IS NULL',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->indexes as $name => $definition) {
            DB::statement(sprintf(
                'CREATE INDEX IF NOT EXISTS %s ON %s (id) WHERE %s',
                $name,
                $definition['table'],
                $definition['predicate'],
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->indexes) as $name) {
            DB::statement('DROP INDEX IF EXISTS '.$name);
        }
    }
};
