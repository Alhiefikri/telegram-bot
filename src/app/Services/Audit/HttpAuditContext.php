<?php

namespace App\Services\Audit;

use App\Models\User;

class HttpAuditContext implements AuditContext
{
    public function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public function ip(): ?string
    {
        return request()?->ip();
    }
}
