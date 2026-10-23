<?php

use Brick\Math\BigDecimal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $usd = DB::table('supplier_currency_rates')->where('currency', 'USD')->first();
        $usdt = DB::table('supplier_currency_rates')->where('currency', 'USDT')->first();
        if ($usd === null && $usdt === null) {
            return;
        }

        // One dollar budget now covers both wallet labels. The highest previous
        // rate is the conservative choice: it cannot silently lower a cost.
        $best = null;
        foreach ([$usd?->exchange_rate_idr, $usdt?->exchange_rate_idr] as $value) {
            if ($value === null) {
                continue;
            }
            $value = (string) $value;
            if ($best === null || BigDecimal::of($value)->isGreaterThan(BigDecimal::of($best))) {
                $best = $value;
            }
        }
        if ($best === null) {
            return;
        }

        if ($usd === null) {
            DB::table('supplier_currency_rates')->insert([
                'currency' => 'USD',
                'exchange_rate_idr' => $best,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('supplier_currency_rates')->where('currency', 'USD')->update([
                'exchange_rate_idr' => $best,
                'version' => DB::raw('version + 1'),
                'updated_at' => now(),
            ]);
        }

        DB::table('suppliers')->whereIn('currency', ['USD', 'USDT'])->update([
            'exchange_rate_idr' => $best,
            'version' => DB::raw('version + 1'),
            'updated_at' => now(),
        ]);
        DB::table('supplier_currency_rates')->where('currency', 'USDT')->delete();
    }

    public function down(): void
    {
        throw new RuntimeException('The merged USD/USDT rate cannot be split losslessly; restore a pre-merge backup instead.');
    }
};
