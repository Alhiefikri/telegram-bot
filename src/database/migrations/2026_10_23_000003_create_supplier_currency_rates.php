<?php

use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_currency_rates', function (Blueprint $table): void {
            $table->string('currency', 8)->primary();
            $table->decimal('exchange_rate_idr', 16, 6);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        // One authoritative rate per wallet currency. Existing connections of the
        // same currency may disagree; the highest stored rate is the conservative
        // choice because it cannot silently lower a product's purchase cost.
        foreach (DB::table('suppliers')->distinct()->pluck('currency') as $currency) {
            $rates = DB::table('suppliers')->where('currency', $currency)->pluck('exchange_rate_idr');
            $best = null;
            foreach ($rates as $rate) {
                $value = (string) $rate;
                if ($best === null || BigDecimal::of($value)->isGreaterThan(BigDecimal::of($best))) {
                    $best = $value;
                }
            }
            if ($best === null) {
                continue;
            }
            DB::table('supplier_currency_rates')->insert([
                'currency' => $currency,
                'exchange_rate_idr' => $best,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Keep the connection cache equal to the new single source. Historical
            // purchase and fulfillment snapshots are separate and remain untouched.
            DB::table('suppliers')->where('currency', $currency)->update([
                'exchange_rate_idr' => $best,
                'version' => DB::raw('version + 1'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_currency_rates');
    }
};
