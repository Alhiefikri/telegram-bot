<?php

namespace App\Console\Commands;

use App\Services\Customer\CustomerAuthMailDeliveryService;
use Illuminate\Console\Command;
use Throwable;

final class RecoverCustomerAuthMail extends Command
{
    protected $signature = 'customer-auth:recover-mail {--limit=100 : Maximum work rows per phase, from 1 to 1000}';

    protected $description = 'Redispatch pending customer authentication mail and remove expired mail secrets.';

    public function handle(CustomerAuthMailDeliveryService $mail): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('Recovery limit must be between 1 and 1000.');

            return self::FAILURE;
        }
        try {
            $count = $mail->recover($limit);
            $this->info("Queued {$count} customer authentication mail work item(s).");

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Customer authentication mail recovery temporarily unavailable.');

            return self::FAILURE;
        }
    }
}
