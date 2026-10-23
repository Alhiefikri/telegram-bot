<?php

use App\Services\Suppliers\SupplierCurrencyRateCutover;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Also repairs installs that ran the original backfill/merge before the
        // cache fence and projection reconciliation were added to those migrations.
        DB::transaction(fn () => SupplierCurrencyRateCutover::reconcile());
    }

    public function down(): void
    {
        throw new RuntimeException('Keep the supplier rate writer fence on rollback; use a qualified pre-cutover backup to return to per-connection rates.');
    }
};
