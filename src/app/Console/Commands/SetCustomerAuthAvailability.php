<?php

namespace App\Console\Commands;

use App\Services\Customer\CustomerAuthAvailability;
use Illuminate\Console\Command;
use Throwable;

final class SetCustomerAuthAvailability extends Command
{
    protected $signature = 'customer-auth:availability
        {mode? : enabled or rollback; omit to read the current state}
        {--confirm : Confirm the availability change}';

    protected $description = 'Read or change new customer auth availability without changing account or guest data.';

    public function handle(CustomerAuthAvailability $availability): int
    {
        $mode = $this->argument('mode');
        if ($mode !== null && (! in_array($mode, ['enabled', 'rollback'], true) || ! $this->option('confirm'))) {
            $this->error('Use enabled or rollback with --confirm to change availability.');

            return self::FAILURE;
        }
        try {
            if ($mode !== null) {
                $availability->setEnabled($mode === 'enabled');
            }
            $this->line(json_encode([
                'new_methods_enabled' => $availability->enabled(),
                'magic_link_guest_recovery' => 'retained',
                'account_data' => 'unchanged',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Customer authentication availability change temporarily unavailable.');

            return self::FAILURE;
        }
    }
}
