<?php

namespace App\Filament\Resources\Products\Tables;

use App\Services\StockAdjustmentService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image_path')->disk('public'),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('price_amount')->formatStateUsing(fn ($state) => Money::idr($state))->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('stock')->sortable(),
                TextColumn::make('reserved_stock')->sortable(),
                IconColumn::make('is_active')->boolean()->sortable(),
                TextColumn::make('deleted_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                RestoreAction::make(),
                Action::make('adjustStock')
                    ->label('Adjust stock')
                    ->authorize('adjustStock')
                    ->schema([
                        TextInput::make('delta')->label('Quantity delta')->required()->integer()->notIn([0]),
                        Textarea::make('notes')->maxLength(1000),
                    ])
                    ->action(fn ($record, array $data, StockAdjustmentService $service) => $service->adjust($record, (int) $data['delta'], $data['notes'] ?? null, auth()->user(), request()->ip())),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
