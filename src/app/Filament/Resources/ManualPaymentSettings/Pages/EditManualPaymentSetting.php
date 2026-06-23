<?php

namespace App\Filament\Resources\ManualPaymentSettings\Pages;

use App\Filament\Resources\ManualPaymentSettings\ManualPaymentSettingResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditManualPaymentSetting extends EditRecord
{
    protected static string $resource = ManualPaymentSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
