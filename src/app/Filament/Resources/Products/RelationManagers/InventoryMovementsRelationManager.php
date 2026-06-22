<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InventoryMovementsRelationManager extends RelationManager
{
    protected static string $relationship = 'inventoryMovements';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('type'),
            TextColumn::make('quantity'),
            TextColumn::make('notes')->wrap(),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }
}
