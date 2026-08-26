<?php

namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['singleton_key', 'approved_by', 'approved_at'])]
class PakasirRetirementApproval extends Model
{
    public const SINGLETON_KEY = 'pakasir_retirement';

    protected function casts(): array
    {
        return [
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public static function current(): ?self
    {
        return self::query()
            ->where('singleton_key', self::SINGLETON_KEY)
            ->first();
    }

    public function hasValidOwnerApproval(): bool
    {
        return $this->approved_at !== null
            && $this->approved_by !== null
            && $this->approver()
                ->where('role', UserRole::Owner->value)
                ->exists();
    }
}
