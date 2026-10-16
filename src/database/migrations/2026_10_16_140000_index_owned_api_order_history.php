<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX orders_api_customer_cursor ON orders (customer_id, id) WHERE api_order_claim_id IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX orders_api_customer_cursor');
    }
};
