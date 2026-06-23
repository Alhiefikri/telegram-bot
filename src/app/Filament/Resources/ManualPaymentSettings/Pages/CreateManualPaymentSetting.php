<?php

namespace App\Filament\Resources\ManualPaymentSettings\Pages;

use App\Filament\Resources\ManualPaymentSettings\ManualPaymentSettingResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateManualPaymentSetting extends CreateRecord
{
    protected static string $resource = ManualPaymentSettingResource::class;

    public static bool $formActionsAreSticky = true;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->submit(null)
            ->action('create');
    }
}
