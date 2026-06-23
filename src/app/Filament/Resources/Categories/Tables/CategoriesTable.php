<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable()->sortable(),
            TextColumn::make('sort_order')->sortable(),
            IconColumn::make('is_active')->boolean()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
            TextColumn::make('deleted_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([TrashedFilter::make()])->recordActions([EditAction::make(), RestoreAction::make()])->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make(), RestoreBulkAction::make()])]);
    }
}
