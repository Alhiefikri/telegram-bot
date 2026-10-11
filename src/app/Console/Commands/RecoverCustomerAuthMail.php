<?php

namespace App\Console\Commands;

use App\Services\Customer\CustomerAuthMailDeliveryService;
use Illuminate\Console\Command;
use Throwable;

final class RecoverCustomerAuthMail extends Command
{
    protected $signature = 'customer-auth:recover-mail {--limit=100}';

    protected $description = 'Redispatch pending customer authentication mail and remove expired mail secrets.';

    public function handle(CustomerAuthMailDeliveryService $mail): int
    {
        try {
            $count = $mail->recover((int) $this->option('limit'));
            $this->info("Queued {$count} customer authentication mail work item(s).");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Customer authentication mail recovery temporarily unavailable.');

            return self::FAILURE;
        }
    }
}
