<?php

namespace App\Console\Commands;

use App\Services\Customer\CustomerAuthRevocation;
use Illuminate\Console\Command;
use Throwable;

final class RevokeCustomerAuthentication extends Command
{
    protected $signature = 'customer-auth:revoke {customer : Internal Customer row ID} {--confirm : Confirm session and challenge revocation}';

    protected $description = 'Revoke one Customer authentication generation and pending challenges without deleting account data.';

    public function handle(CustomerAuthRevocation $revocation): int
    {
        $id = filter_var($this->argument('customer'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1 || ! $this->option('confirm')) {
            $this->error('A positive internal Customer ID and --confirm are required.');

            return self::FAILURE;
        }
        try {
            if (! $revocation->revoke($id)) {
                $this->error('Customer authentication revocation could not be completed.');

                return self::FAILURE;
            }
            $this->info('Customer sessions and pending challenges revoked. Account, Order, balance, identity and Recovery Key data retained.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Customer authentication revocation temporarily unavailable.');

            return self::FAILURE;
        }
    }
}
