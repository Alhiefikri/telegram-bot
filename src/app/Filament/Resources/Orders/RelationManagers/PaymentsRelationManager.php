<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('provider'), TextColumn::make('method'), TextColumn::make('merchant_ref'), TextColumn::make('provider_reference'), TextColumn::make('amount')->money('IDR', divideBy: 100), TextColumn::make('status')->badge(), TextColumn::make('paid_at')->dateTime(), TextColumn::make('expired_at')->dateTime()]);
    }
}
