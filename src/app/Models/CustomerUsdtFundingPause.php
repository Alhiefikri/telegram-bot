<?php

namespace App\Models;

use App\Enums\CustomerUsdtFundingPauseScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One Owner pause row for a USDT funding scope. Presence means paused; resume
 * removes the row. The row is operational state, never money: the audit log
 * keeps the transition history.
 */
#[Fillable(['scope', 'reason', 'paused_by_user_id', 'paused_at'])]
class CustomerUsdtFundingPause extends Model
{
    protected function casts(): array
    {
        return [
            'scope' => CustomerUsdtFundingPauseScope::class,
            'paused_at' => 'datetime',
        ];
    }
}
