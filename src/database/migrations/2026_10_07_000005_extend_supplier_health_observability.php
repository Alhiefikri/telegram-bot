<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_health_states', function (Blueprint $table): void {
            $table->boolean('balance_stale_active')->default(false);
        });
        // Preserve consumed episodes at cutover: state changes are not new episodes.
        DB::table('supplier_health_reviews')->orderBy('supplier_fulfillment_id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $row) {
                    $identities = [];
                    foreach (json_decode($row->active_identities, true, 512, JSON_THROW_ON_ERROR) as $identity => $supplierId) {
                        $identities[implode(':', array_slice(explode(':', $identity), 0, 3))] = $supplierId;
                    }
                    DB::table('supplier_health_reviews')->where('supplier_fulfillment_id', $row->supplier_fulfillment_id)
                        ->update(['active_identities' => json_encode($identities, JSON_THROW_ON_ERROR)]);
                }
            }, 'supplier_fulfillment_id');
    }

    public function down(): void
    {
        Schema::table('supplier_health_states', fn (Blueprint $table) => $table->dropColumn('balance_stale_active'));
    }
};
