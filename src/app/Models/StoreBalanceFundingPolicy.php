<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['singleton_key', 'minimum_amount', 'preset_amounts', 'version'])]
class StoreBalanceFundingPolicy extends Model
{
    public const SINGLETON_KEY = 'store_balance_funding_policy';

    public const DEFAULT_MINIMUM_AMOUNT = 5_000;

    /** @var list<int> */
    public const DEFAULT_PRESET_AMOUNTS = [5_000, 10_000, 25_000, 50_000, 100_000, 250_000];

    public const MAX_PRESETS = 12;

    protected function casts(): array
    {
        return [
            'minimum_amount' => 'integer',
            'preset_amounts' => 'array',
            'version' => 'integer',
        ];
    }

    public static function current(): ?self
    {
        return self::query()->where('singleton_key', self::SINGLETON_KEY)->first();
    }

    public function minimumAmount(): int
    {
        return (int) $this->minimum_amount;
    }

    /** @return list<int> */
    public function presetAmounts(): array
    {
        return array_values(array_map(
            static fn (mixed $amount): int => (int) $amount,
            is_array($this->preset_amounts) ? $this->preset_amounts : [],
        ));
    }

    public function policyVersion(): int
    {
        return (int) $this->version;
    }
}
