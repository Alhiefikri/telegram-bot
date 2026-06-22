<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['stock'], $data['reserved_stock']);

        return $data + ['stock' => 0, 'reserved_stock' => 0];
    }

    protected function afterCreate(): void
    {
        app(AuditLogger::class)->log('product.created', $this->record, null, $this->record->only(['name', 'description', 'price_amount', 'currency', 'image_path', 'is_active', 'stock', 'reserved_stock']), auth()->user(), request()->ip());
    }
}
