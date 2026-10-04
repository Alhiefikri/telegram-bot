<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierHealthService;
use Illuminate\Console\Command;

final class NotifySupplierHealth extends Command
{
    protected $signature = 'suppliers:notify-health {--limit=25 : Maximum connections, obligations and recovery effects (1-100)}';

    protected $description = 'Materialize private supplier health alerts using durable episodes without supplier HTTP';

    public function handle(SupplierHealthService $health): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            $this->error('Gunakan limit antara 1 dan 100.');

            return self::INVALID;
        }
        $result = $health->run($limit);
        if ($result['missing_target']) {
            $this->warn('Target admin Telegram belum dikonfigurasi; tidak ada dedupe yang dikonsumsi.');

            return self::SUCCESS;
        }
        $this->info('Health supplier: '.$result['materialized'].' effect dibuat, '.$result['dispatched'].' wakeup delivery. Status sent/ambigu ada di ledger outbound.');

        return self::SUCCESS;
    }
}
