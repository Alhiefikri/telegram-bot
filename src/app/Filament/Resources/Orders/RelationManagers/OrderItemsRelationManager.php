<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'orderItems';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('product_name'), TextColumn::make('quantity'), TextColumn::make('unit_price')->money('IDR', divideBy: 100), TextColumn::make('line_total')->money('IDR', divideBy: 100)]);
    }
}
