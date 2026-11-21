<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StarsPricingPolicyRecord extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'stars_pricing_policies';

    public $incrementing = false;

    protected function casts(): array
    {
        return [
            'idr_per_usd' => 'integer',
            'reward_micro_usd' => 'integer',
            'reserve_bps' => 'integer',
            'issuance_enabled' => 'boolean',
            'version' => 'integer',
        ];
    }
}
