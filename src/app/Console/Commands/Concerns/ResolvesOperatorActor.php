<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;

/**
 * Resolves the acting Owner for operator receiver-mapping commands from an
 * exact numeric user ID or email. Authorization itself stays in the owner
 * service so every caller shares one gate.
 */
trait ResolvesOperatorActor
{
    private function resolveOperatorActor(string $option = 'actor'): User
    {
        $value = trim((string) ($this->option($option) ?? ''));
        if ($value === '') {
            throw new InvalidArgumentException("The --{$option} option is required.");
        }

        $actor = ctype_digit($value)
            ? User::query()->find((int) $value)
            : User::query()->where('email', $value)->first();

        if ($actor === null) {
            throw new ModelNotFoundException('Actor Owner tidak ditemukan untuk --actor yang diberikan.');
        }

        return $actor;
    }
}
