<?php

namespace App\Filament\Resources\Products\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('action')->searchable(),
            TextColumn::make('user.email')->label('User'),
            TextColumn::make('old_values')->json()->toggleable(),
            TextColumn::make('new_values')->json()->toggleable(),
            TextColumn::make('ip_address'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }
}
