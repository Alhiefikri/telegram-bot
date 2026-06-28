<?php

namespace App\Services\Audit;

use App\Models\User;

interface AuditContext
{
    public function user(): ?User;

    public function ip(): ?string;
}
