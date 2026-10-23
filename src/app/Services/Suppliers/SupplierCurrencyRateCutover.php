<?php

namespace App\Services\Suppliers;

use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Atomic expand-migration reconciliation while baseline application writers are still running. */
final class SupplierCurrencyRateCutover
{
    public static function backfill(): void
    {
        self::lockResources();
        foreach (DB::table('suppliers')->distinct()->pluck('currency') as $currency) {
            $best = self::highest(DB::table('suppliers')->where('currency', $currency)->pluck('exchange_rate_idr')->all());
            DB::table('supplier_currency_rates')->insert([
                'currency' => $currency,
                'exchange_rate_idr' => $best,
                'version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            self::updateCaches([$currency], $best);
        }
        self::installFence();
        self::refreshProducts();
    }

    public static function mergeDollars(): void
    {
        self::lockResources();
        $rows = DB::table('supplier_currency_rates')->whereIn('currency', ['USD', 'USDT'])->get()->keyBy('currency');
        if ($rows->isNotEmpty()) {
            $best = self::highest($rows->pluck('exchange_rate_idr')->all());
            if ($rows->has('USD')) {
                DB::table('supplier_currency_rates')->where('currency', 'USD')->update([
                    'exchange_rate_idr' => $best,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('supplier_currency_rates')->insert([
                    'currency' => 'USD', 'exchange_rate_idr' => $best, 'version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            // Once the old key is gone, the fence resolves USDT caches through USD.
            DB::table('supplier_currency_rates')->where('currency', 'USDT')->delete();
            self::updateCaches(['USD', 'USDT'], $best);
        }
        self::installFence();
        self::refreshProducts();
    }

    public static function reconcile(): void
    {
        self::lockResources();
        self::installFence();
        foreach (DB::table('suppliers')->distinct()->pluck('currency') as $currency) {
            $key = $currency === 'USDT' ? 'USD' : $currency;
            $rate = DB::table('supplier_currency_rates')->where('currency', $key)->value('exchange_rate_idr');
            if ($rate === null) {
                throw new RuntimeException('Supplier currency has no authoritative rate; restore or qualify the missing rate before retrying cutover.');
            }
            DB::table('suppliers')->where('currency', $currency)->where('exchange_rate_idr', '<>', $rate)->update([
                'exchange_rate_idr' => $rate,
                'version' => DB::raw('version + 1'),
                'sync_token' => null,
                'updated_at' => now(),
            ]);
        }
        self::refreshProducts();
    }

    public static function dropFence(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS supplier_currency_rate_cache_guard ON suppliers');
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_supplier_currency_rate_cache()');
        } elseif (DB::getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS supplier_currency_rate_cache_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS supplier_currency_rate_cache_update_guard');
        }
    }

    private static function lockResources(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('Supplier currency cutover requires an enclosing transaction.');
        }
        if (DB::getDriverName() === 'pgsql') {
            // The expand phase has live old-image writers. Table locks also close
            // phantom mapping/connection inserts, without Supplier -> Product locks.
            DB::statement('LOCK TABLE supplier_currency_rates IN EXCLUSIVE MODE');
            DB::statement('LOCK TABLE products IN ACCESS EXCLUSIVE MODE');
            DB::statement('LOCK TABLE suppliers IN ACCESS EXCLUSIVE MODE');
            DB::statement('LOCK TABLE supplier_catalog_items, product_supplier_sources IN ACCESS EXCLUSIVE MODE');
        } elseif (DB::getDriverName() !== 'sqlite') {
            throw new RuntimeException('Supplier currency cutover supports PostgreSQL and SQLite only.');
        }
    }

    private static function installFence(): void
    {
        $rate = "COALESCE((SELECT exchange_rate_idr FROM supplier_currency_rates WHERE currency = NEW.currency), (SELECT exchange_rate_idr FROM supplier_currency_rates WHERE currency = 'USD' AND NEW.currency = 'USDT'))";
        if (DB::getDriverName() === 'pgsql') {
            // VOLATILE reads the committed authority after any Supplier row-lock
            // wait, rather than retaining the UPDATE statement's older snapshot.
            // No rate-row lock is taken here: that would reverse the writer order.
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION enforce_supplier_currency_rate_cache() RETURNS trigger AS $$
                BEGIN
                    IF NEW.exchange_rate_idr IS DISTINCT FROM {$rate} THEN
                        RAISE EXCEPTION 'Supplier rate cache must match the authoritative currency rate'
                            USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql VOLATILE
                SQL);
            DB::unprepared('DROP TRIGGER IF EXISTS supplier_currency_rate_cache_guard ON suppliers');
            DB::unprepared('CREATE TRIGGER supplier_currency_rate_cache_guard BEFORE INSERT OR UPDATE OF currency, exchange_rate_idr ON suppliers FOR EACH ROW EXECUTE FUNCTION enforce_supplier_currency_rate_cache()');
        } else {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE OF currency, exchange_rate_idr'] as $name => $event) {
                DB::unprepared(<<<SQL
                    CREATE TRIGGER IF NOT EXISTS supplier_currency_rate_cache_{$name}_guard
                    BEFORE {$event} ON suppliers
                    WHEN NEW.exchange_rate_idr IS NOT {$rate}
                    BEGIN
                        SELECT RAISE(ABORT, 'Supplier rate cache must match the authoritative currency rate');
                    END
                    SQL);
            }
        }
    }

    private static function updateCaches(array $currencies, string $rate): void
    {
        DB::table('suppliers')->whereIn('currency', $currencies)->update([
            'exchange_rate_idr' => $rate,
            'version' => DB::raw('version + 1'),
            'sync_token' => null,
            'updated_at' => now(),
        ]);
    }

    private static function highest(array $rates): string
    {
        $best = (string) array_shift($rates);
        foreach ($rates as $rate) {
            if (BigDecimal::of((string) $rate)->isGreaterThan(BigDecimal::of($best))) {
                $best = (string) $rate;
            }
        }

        return $best;
    }

    private static function refreshProducts(): void
    {
        $ids = DB::table('product_supplier_sources')->distinct()->orderBy('product_id')->pluck('product_id')->all();
        app(SupplierAvailability::class)->refreshProducts($ids);
    }
}
