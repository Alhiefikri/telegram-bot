<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\AuditLogger;
use App\Services\Telegram\TelegramProductNotificationService;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    public static bool $formActionsAreSticky = true;

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()
            ->submit(null)
            ->action('create');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['stock'], $data['reserved_stock']);

        return $data + ['stock' => 0, 'reserved_stock' => 0];
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log('product.created', $this->record, null, $this->record->only(['category_id', 'name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']), auth()->user(), request()->ip());
        app(TelegramProductNotificationService::class)->notifyProductCreated($this->record);
    }
}
