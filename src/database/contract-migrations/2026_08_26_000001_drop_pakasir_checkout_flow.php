<?php

use App\Services\Pakasir\PakasirRetirementGate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * This migration is deliberately outside Laravel's expand migration path.
     * Run it only in the explicit contract release after the retirement gate
     * has passed; a blocked gate must leave the compatibility schema intact.
     */
    public function up(): void
    {
        app(PakasirRetirementGate::class)->assertSatisfied();

        if (! Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow')) {
            return;
        }

        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->dropColumn('pakasir_checkout_flow');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('manual_payment_settings', 'pakasir_checkout_flow')) {
            return;
        }

        Schema::table('manual_payment_settings', function (Blueprint $table): void {
            $table->string('pakasir_checkout_flow')->default('hosted')->after('payment_mode');
        });
    }
};
