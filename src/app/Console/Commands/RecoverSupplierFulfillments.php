<?php

namespace App\Console\Commands;

use App\Services\Suppliers\SupplierFulfillmentService;
use Illuminate\Console\Command;

final class RecoverSupplierFulfillments extends Command
{
    protected $signature = 'suppliers:recover {--limit=100 : Maximum due fulfillments to process}';

    protected $description = 'Recover bounded durable paid fulfillment work without retrying unconfirmed purchases';

    public function handle(SupplierFulfillmentService $fulfillment): int
    {
        $result = $fulfillment->recover((int) $this->option('limit'));
        $this->info('Processed '.$result['processed'].' due fulfillment records.');

        return self::SUCCESS;
    }
}
