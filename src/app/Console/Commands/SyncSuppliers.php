<?php

namespace App\Console\Commands;

use App\Models\Supplier;
use App\Services\Suppliers\SupplierCatalogSync;
use Illuminate\Console\Command;

final class SyncSuppliers extends Command
{
    protected $signature = 'suppliers:sync {--supplier= : Sync one private connection ID} {--limit=25 : Maximum connections per run (1-100)}';

    protected $description = 'Refresh bounded supplier catalog and balance observations without purchasing';

    public function handle(SupplierCatalogSync $sync): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $supplierId = $this->option('supplier');
        if ($limit === false || $limit < 1 || $limit > 100
            || ($supplierId !== null && (! ctype_digit((string) $supplierId) || (int) $supplierId < 1))) {
            $this->error('Use a valid supplier ID and a limit between 1 and 100.');

            return self::INVALID;
        }
        $connections = Supplier::query()->where('is_active', true)
            ->when($supplierId !== null,
                fn ($query) => $query->whereKey((int) $supplierId),
                fn ($query) => $query->where(fn ($due) => $due
                    ->whereNull('synced_at')
                    ->orWhere('synced_at', '<=', now()->subMinute())
                    ->orWhereNull('sync_expires_at')
                    ->orWhere('sync_expires_at', '<=', now()->addMinute())),
            )
            ->where(fn ($query) => $query->whereNull('sync_next_attempt_at')->orWhere('sync_next_attempt_at', '<=', now())
                ->when($supplierId !== null, fn ($manual) => $manual->orWhere('sync_failures', 0)))
            ->where(fn ($query) => $query->whereNull('sync_token')->orWhere('sync_lease_expires_at', '<=', now()))
            ->orderByRaw('CASE WHEN sync_attempted_at IS NULL THEN 0 ELSE 1 END')->orderBy('sync_attempted_at')->orderBy('id')
            ->limit($limit)->get();
        $applied = 0;
        $processed = 0;
        $deadline = hrtime(true) + 45_000_000_000;
        foreach ($connections as $connection) {
            if (hrtime(true) >= $deadline) {
                break;
            }
            $processed++;
            $applied += $sync->sync($connection) ? 1 : 0;
        }
        $this->info('Supplier snapshots applied: '.$applied.'; unavailable or superseded: '.($processed - $applied).'.');

        return self::SUCCESS;
    }
}
