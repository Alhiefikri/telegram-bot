<?php

namespace App\Console\Commands;

use App\Services\Telegram\FakeSalesProofPostPublisher;
use Illuminate\Console\Command;

class PublishAutomaticFakeSalesProof extends Command
{
    protected $signature = 'telegram:fake-sales-proof:auto';

    protected $description = 'Publish an automatic fake sales proof post when its random interval is due.';

    public function handle(FakeSalesProofPostPublisher $publisher): int
    {
        $result = $publisher->publishAutomatically();
        $message = $result['message'];

        if ($result['success']) {
            $this->info($message);

            return self::SUCCESS;
        }

        if (($result['skipped'] ?? false) === true) {
            $this->line($message);

            return self::SUCCESS;
        }

        $this->error($message);

        return self::FAILURE;
    }
}
