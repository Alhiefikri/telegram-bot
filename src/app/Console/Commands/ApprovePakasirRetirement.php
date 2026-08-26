<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Pakasir\PakasirRetirementGate;
use Illuminate\Console\Command;
use Throwable;

class ApprovePakasirRetirement extends Command
{
    protected $signature = 'pakasir:retirement-approve {owner : Owner user id or email}';

    protected $description = 'Record the explicit owner approval for the gated Pakasir compatibility contract release.';

    public function handle(PakasirRetirementGate $gate): int
    {
        $owner = $this->owner();
        if ($owner === null) {
            $this->error('The supplied user is not an owner or does not exist.');

            return self::FAILURE;
        }

        try {
            $gate->approve($owner);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Pakasir retirement owner approval recorded.');

        return self::SUCCESS;
    }

    private function owner(): ?User
    {
        $value = trim((string) $this->argument('owner'));
        if ($value === '') {
            return null;
        }

        $query = User::query()->where('role', 'owner');
        if (ctype_digit($value)) {
            $query->whereKey((int) $value);
        } else {
            $query->where('email', $value);
        }

        return $query->first();
    }
}
