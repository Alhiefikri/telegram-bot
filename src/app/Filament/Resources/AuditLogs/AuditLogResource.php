<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\EditAuditLog;
use App\Filament\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('action')->searchable()->sortable(),
            TextColumn::make('subject_type'),
            TextColumn::make('subject_id'),
            TextColumn::make('user.email')->label('User'),
            TextColumn::make('old_values')->formatStateUsing(fn ($state): ?string => self::formatJsonState($state))->placeholder('—')->wrap()->limit(120)->toggleable(),
            TextColumn::make('new_values')->formatStateUsing(fn ($state): ?string => self::formatJsonState($state))->placeholder('—')->wrap()->limit(120)->toggleable(),
            TextColumn::make('ip_address'),
            TextColumn::make('created_at')->dateTime()->sortable(),
        ]);
    }

    protected static function formatJsonState(mixed $state): ?string
    {
        if ($state === null || $state === []) {
            return null;
        }

        return json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: (string) $state;
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/'), 'edit' => EditAuditLog::route('/{record}/edit')];
    }
}
