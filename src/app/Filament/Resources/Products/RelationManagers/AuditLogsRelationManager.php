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
            TextColumn::make('old_values')->formatStateUsing(fn ($state): ?string => $this->formatJsonState($state))->placeholder('—')->wrap()->limit(120)->toggleable(),
            TextColumn::make('new_values')->formatStateUsing(fn ($state): ?string => $this->formatJsonState($state))->placeholder('—')->wrap()->limit(120)->toggleable(),
            TextColumn::make('ip_address'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }

    protected function formatJsonState(mixed $state): ?string
    {
        if ($state === null || $state === []) {
            return null;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $state;
    }
}
