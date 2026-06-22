<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    /** @var array<string, mixed> */
    private array $auditOldValues = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['stock'], $data['reserved_stock']);

        return $data;
    }

    protected function beforeSave(): void
    {
        $this->auditOldValues = $this->record->only(['name', 'description', 'price_amount', 'currency', 'image_path', 'is_active']);
    }

    protected function afterSave(): void
    {
        $newValues = $this->record->fresh()->only(array_keys($this->auditOldValues));
        $oldValues = array_intersect_key($this->auditOldValues, array_diff_assoc($newValues, $this->auditOldValues));
        $changes = array_intersect_key($newValues, $oldValues);

        if ($changes !== []) {
            app(AuditLogger::class)->log('product.updated', $this->record, $oldValues, $changes, auth()->user(), request()->ip());
        }
    }
}
