<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'telegram_outbound_effects_dispatch_family_idx';

    /**
     * Keep the partial index aligned with the full retryable predicate. The
     * first definition excluded only HTTP 400/403, so terminal families such as
     * stock notifications (sold_out_stale) stayed in the index and forced every
     * recovery sweep to scan them before the query filter could reject them.
     */
    private const NON_RETRYABLE_FAILURE_CODES = [
        'warranty_notification_unavailable',
        'warranty_reminder_unavailable',
        'warranty_reminder_expired',
        'warranty_reminder_no_longer_eligible',
        'checkout_expired',
        'checkout_not_pending',
        'checkout_payment_missing',
        'checkout_payment_not_pending',
        'receipt_not_eligible',
        'receipt_render_changed',
        'balance_top_up_missing',
        'balance_top_up_not_payable',
        'balance_top_up_expired',
        'balance_top_up_receipt_missing',
        'usdt_receipt_missing',
        'balance_operations_alert_missing',
        'balance_top_up_not_paid',
        'balance_owner_compensation_missing',
        'balance_owner_compensation_not_eligible',
        'balance_top_up_owner_alert_not_eligible',
        'balance_top_up_proof_not_eligible',
        'receipt_txt_credentials_missing',
        'receipt_txt_render_failed',
        'receipt_actions_not_eligible',
        'receipt_actions_render_failed',
        'admin_target_missing',
        'admin_notification_not_eligible',
        'proof_not_eligible',
        'proof_attachment_missing',
        'proof_already_forwarded',
        'proof_disabled',
        'proof_target_missing',
        'telegram_http_400',
        'telegram_http_403',
        'broadcast_recipient_attempts_exhausted',
        'broadcast_recipient_terminal_failure',
        'stock_notification_recipient_missing',
        'stock_notification_attempts_exhausted',
        'stock_notification_terminal_failure',
        'sold_out_stale',
        'price_change_recipient_missing',
        'price_change_event_missing',
        'price_change_recipient_attempts_exhausted',
        'price_change_store_channel_attempts_exhausted',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceIndex($this->retryablePredicate());
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceIndex("state = 'pending' OR (state = 'failed' AND (last_error_code IS NULL OR last_error_code NOT IN ('telegram_http_400', 'telegram_http_403')))");
    }

    private function retryablePredicate(): string
    {
        $codes = implode(', ', array_map(
            static fn (string $code): string => DB::getPdo()->quote($code),
            self::NON_RETRYABLE_FAILURE_CODES,
        ));

        return "state = 'pending' OR (state = 'failed' AND (last_error_code IS NULL OR last_error_code NOT IN ({$codes})))";
    }

    private function replaceIndex(string $predicate): void
    {
        // A failed concurrent build can leave an invalid index behind; drop it
        // before the recreation so the name is never silently reused.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
        DB::statement(
            'CREATE INDEX CONCURRENTLY '.self::INDEX
            .' ON telegram_outbound_effects (effect_type, id) WHERE '.$predicate,
        );
    }
};
