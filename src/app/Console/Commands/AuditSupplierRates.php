<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Models\User;
use App\Services\Suppliers\SupplierExchangeRate;
use App\Services\Suppliers\SupplierManager;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Read-only audit of supplier conversion rates that fell outside the plausible band
 * (e.g. a rupiah-per-USDT 18.000 stored for a VND wallet), with an explicit operator
 * opt-in to replace them. Corrections reuse SupplierManager::correctRate(), so the
 * commitment guards, the lock order and the availability refresh stay in one place.
 */
final class AuditSupplierRates extends Command
{
    protected $signature = 'suppliers:rate-audit {--fix} {--to= : Nilai rupiah pengganti untuk 1 unit mata uang supplier} {--force : Lewati konfirmasi interaktif} {--actor= : ID user admin untuk jejak audit} {--limit=200 : Maksimum koneksi yang diaudit (1-500)}';

    protected $description = 'Audit koneksi supplier yang kurs rupiahnya di luar rentang wajar, dan perbaiki hanya bila diminta';

    public function handle(SupplierManager $manager): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500) {
            $this->error('Batas audit harus bilangan bulat antara 1 dan 500.');

            return self::INVALID;
        }

        $actor = null;
        $actorOption = $this->option('actor');
        if (is_string($actorOption) && $actorOption !== '') {
            if (! ctype_digit($actorOption) || (int) $actorOption < 1) {
                $this->error('ID user admin untuk jejak audit harus bilangan bulat positif.');

                return self::INVALID;
            }
            $actor = User::query()->whereKey((int) $actorOption)->first();
            if ($actor === null) {
                $this->error('User admin untuk jejak audit tidak ditemukan.');

                return self::INVALID;
            }
        }

        $fix = (bool) $this->option('fix');
        $replacement = $this->option('to');
        $replacement = is_string($replacement) && trim($replacement) !== '' ? trim($replacement) : null;
        if ($fix && $replacement === null) {
            $this->error('Sertakan --to berisi nilai rupiah pengganti untuk 1 unit mata uang supplier.');

            return self::INVALID;
        }

        $scanned = Supplier::query()->orderBy('id')->limit($limit)->get();
        $connections = $scanned->filter(
            static fn (Supplier $connection): bool => ! SupplierExchangeRate::isPlausible($connection->currency, $connection->exchange_rate_idr),
        )->values();
        // One query gathers the mapped products of every flagged connection: the audit
        // must never grow a query per supplier, and never a query per product.
        $mappedProducts = $this->mappedProductCounts($connections);

        $failed = false;
        foreach ($connections as $connection) {
            $this->line(sprintf(
                'Koneksi #%d %s %s: kurs %s di luar rentang wajar. Produk terkait: %d.',
                $connection->id,
                (string) $connection->provider,
                (string) $connection->currency,
                (string) $connection->exchange_rate_idr,
                $mappedProducts[(int) $connection->id] ?? 0,
            ));
        }

        $applicable = [];
        if ($fix) {
            foreach ($connections as $connection) {
                if (SupplierExchangeRate::isPlausible($connection->currency, $replacement)) {
                    $applicable[] = $connection;

                    continue;
                }
                $failed = true;
                $this->error(sprintf(
                    'Koneksi #%d: %s',
                    $connection->id,
                    SupplierExchangeRate::requirement((string) $connection->provider, (string) $connection->currency)
                        ?? 'Kurs pengganti tidak wajar untuk mata uang koneksi ini.',
                ));
            }
        }

        if ($fix && $applicable !== []) {
            if (! $this->option('force') && ! $this->confirm(sprintf('Terapkan kurs %s ke seluruh koneksi di atas?', $replacement))) {
                $this->line('Tidak ada perubahan.');

                return self::FAILURE;
            }

            $corrected = 0;
            foreach ($applicable as $connection) {
                try {
                    $corrected += $manager->correctRate($connection, $replacement, $actor, null) ? 1 : 0;
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    $failed = true;
                    $this->error(sprintf('Koneksi #%d: %s', $connection->id, $exception->getMessage()));
                }
            }
            $this->line(sprintf('Kurs diperbaiki pada %d koneksi.', $corrected));
        }

        $this->line(sprintf(
            'Ringkasan: %d dari %d koneksi diperiksa memiliki kurs di luar rentang wajar.',
            $connections->count(),
            $scanned->count(),
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Collection<int, Supplier>  $connections
     * @return array<int, int>
     */
    private function mappedProductCounts(Collection $connections): array
    {
        if ($connections->isEmpty()) {
            return [];
        }

        return DB::table('product_supplier_sources')
            ->join('supplier_catalog_items', 'supplier_catalog_items.id', '=', 'product_supplier_sources.supplier_catalog_item_id')
            ->whereIn('supplier_catalog_items.supplier_id', $connections->modelKeys())
            ->distinct()
            ->get(['supplier_catalog_items.supplier_id', 'product_supplier_sources.product_id'])
            ->groupBy('supplier_id')
            ->map(static fn (Collection $rows): int => $rows->count())
            ->all();
    }
}
