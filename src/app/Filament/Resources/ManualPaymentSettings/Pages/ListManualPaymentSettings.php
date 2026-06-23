<?php

namespace App\Filament\Resources\ManualPaymentSettings\Pages;

use App\Filament\Resources\ManualPaymentSettings\ManualPaymentSettingResource;
use App\Models\ManualPaymentSetting;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListManualPaymentSettings extends ListRecords
{
    protected static string $resource = ManualPaymentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn () => ! ManualPaymentSetting::query()->exists())];
    }
}
