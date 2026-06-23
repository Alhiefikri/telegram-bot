<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrderItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'orderItems';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('product_name'), TextColumn::make('quantity'), TextColumn::make('unit_price')->formatStateUsing(fn ($state) => Money::idr($state)), TextColumn::make('subtotal')->formatStateUsing(fn ($state) => Money::idr($state))]);
    }
}
