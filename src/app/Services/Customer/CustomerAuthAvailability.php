<?php

namespace App\Services\Customer;

use App\Models\CustomerAuthSetting;
use Throwable;

final class CustomerAuthAvailability
{
    public function enabled(): bool
    {
        try {
            return CustomerAuthSetting::query()->where('singleton_key', CustomerAuthSetting::SINGLETON_KEY)
                ->value('enabled') === true;
        } catch (Throwable) {
            return false;
        }
    }

    public function setEnabled(bool $enabled): void
    {
        CustomerAuthSetting::query()->upsert([[
            'singleton_key' => CustomerAuthSetting::SINGLETON_KEY,
            'enabled' => $enabled,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['singleton_key'], ['enabled', 'updated_at']);
    }
}
