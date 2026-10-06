<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_balance_accounts', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'store_balance_accounts_time_cursor');
        });
        Schema::table('customer_usdt_balance_accounts', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'usdt_balance_accounts_time_cursor');
        });
        Schema::table('store_balance_entries', function (Blueprint $table): void {
            $table->index(['customer_balance_account_id', 'created_at', 'id'], 'store_balance_entries_account_time_cursor');
            $table->index(['created_at', 'id'], 'store_balance_entries_time_cursor');
        });
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->index(['customer_id', 'created_at', 'id'], 'store_balance_top_ups_customer_time_cursor');
            $table->index(['created_at', 'id'], 'store_balance_top_ups_time_cursor');
        });
        Schema::table('customer_usdt_balance_entries', function (Blueprint $table): void {
            $table->index(['customer_usdt_balance_account_id', 'created_at', 'id'], 'usdt_entries_account_time_cursor');
            $table->index(['created_at', 'id'], 'usdt_entries_time_cursor');
        });
        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->index(['customer_id', 'created_at', 'id'], 'usdt_topups_customer_time_cursor');
            $table->index(['created_at', 'id'], 'usdt_topups_time_cursor');
        });
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->index(['customer_id', 'created_at', 'id'], 'balance_adjustments_customer_time_cursor');
            $table->index(['created_at', 'id'], 'balance_adjustments_time_cursor');
        });
        Schema::table('usdt_top_up_review_requests', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'usdt_reviews_time_cursor');
        });
        Schema::table('usdt_treasury_movements', function (Blueprint $table): void {
            $table->index(['occurred_at', 'id'], 'usdt_treasury_time_cursor');
        });
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->index(['created_at', 'id'], 'audit_logs_time_cursor');
            $table->index(['action', 'created_at', 'id'], 'audit_logs_action_time_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropIndex('audit_logs_action_time_cursor');
            $table->dropIndex('audit_logs_time_cursor');
        });
        Schema::table('usdt_treasury_movements', function (Blueprint $table): void {
            $table->dropIndex('usdt_treasury_time_cursor');
        });
        Schema::table('usdt_top_up_review_requests', function (Blueprint $table): void {
            $table->dropIndex('usdt_reviews_time_cursor');
        });
        Schema::table('balance_adjustment_requests', function (Blueprint $table): void {
            $table->dropIndex('balance_adjustments_time_cursor');
            $table->dropIndex('balance_adjustments_customer_time_cursor');
        });
        Schema::table('customer_usdt_top_ups', function (Blueprint $table): void {
            $table->dropIndex('usdt_topups_time_cursor');
            $table->dropIndex('usdt_topups_customer_time_cursor');
        });
        Schema::table('customer_usdt_balance_entries', function (Blueprint $table): void {
            $table->dropIndex('usdt_entries_time_cursor');
            $table->dropIndex('usdt_entries_account_time_cursor');
        });
        Schema::table('store_balance_top_ups', function (Blueprint $table): void {
            $table->dropIndex('store_balance_top_ups_time_cursor');
            $table->dropIndex('store_balance_top_ups_customer_time_cursor');
        });
        Schema::table('customer_usdt_balance_accounts', function (Blueprint $table): void {
            $table->dropIndex('usdt_balance_accounts_time_cursor');
        });
        Schema::table('customer_balance_accounts', function (Blueprint $table): void {
            $table->dropIndex('store_balance_accounts_time_cursor');
        });
        Schema::table('store_balance_entries', function (Blueprint $table): void {
            $table->dropIndex('store_balance_entries_time_cursor');
            $table->dropIndex('store_balance_entries_account_time_cursor');
        });
    }
};
