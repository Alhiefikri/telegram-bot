<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\KlikQris\KlikQrisPayloadSanitizer;
use App\Services\Pakasir\PakasirPayloadSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    public function log(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?User $user = null, ?string $ip = null): AuditLog
    {
        $userId = $user?->getKey() ?? Auth::guard('web')->id();

        return $this->write($action, $subject, $old, $new, $userId === null ? null : (int) $userId, $ip ?? request()?->ip());
    }

    public function logWithResolvedContext(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null, ?User $user = null, ?string $ip = null): AuditLog
    {
        $userId = $user?->getKey();

        return $this->write($action, $subject, $old, $new, $userId === null ? null : (int) $userId, $ip);
    }

    private function write(string $action, ?Model $subject, ?array $old, ?array $new, ?int $userId, ?string $ip): AuditLog
    {
        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject ? (string) $subject->getKey() : null,
            'old_values' => $old === null ? null : KlikQrisPayloadSanitizer::sanitize(PakasirPayloadSanitizer::sanitize($old)),
            'new_values' => $new === null ? null : KlikQrisPayloadSanitizer::sanitize(PakasirPayloadSanitizer::sanitize($new)),
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
