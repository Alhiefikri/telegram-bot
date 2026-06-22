<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?User $user = null, ?string $ip = null): AuditLog
    {
        return AuditLog::create([
            'user_id' => $user?->id ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $ip ?? request()?->ip(),
            'created_at' => now(),
        ]);
    }
}
