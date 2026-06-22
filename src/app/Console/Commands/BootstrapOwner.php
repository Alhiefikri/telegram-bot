<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class BootstrapOwner extends Command
{
    protected $signature = 'admin:bootstrap-owner {email?} {--force : Allow creating or promoting an owner when an owner already exists}';

    protected $description = 'Securely create or promote the first owner account.';

    public function handle(): int
    {
        if (User::query()->where('role', UserRole::Owner->value)->exists() && ! $this->option('force')) {
            $this->error('An owner already exists. Re-run with --force if you intentionally need to create or promote another owner.');

            return self::FAILURE;
        }

        $email = $this->argument('email') ?: $this->ask('Owner email');
        $name = $this->ask('Owner name', 'Owner');
        $password = $this->secret('Owner password');

        validator(compact('email', 'name', 'password'), [
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()],
        ])->validate();

        User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password), 'role' => UserRole::Owner]
        );

        $this->info('Owner account is ready.');

        return self::SUCCESS;
    }
}
