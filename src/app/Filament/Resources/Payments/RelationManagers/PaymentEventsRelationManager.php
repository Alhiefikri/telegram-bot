<?php

namespace App\Filament\Resources\Payments\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'paymentEvents';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('event_type')->badge(),
            TextColumn::make('provider'),
            TextColumn::make('merchant_ref'),
            TextColumn::make('provider_reference'),
            TextColumn::make('provider_status'),
            TextColumn::make('raw_payload')->formatStateUsing(fn ($state): ?string => $this->formatJsonState($state))->placeholder('—')->wrap()->limit(120)->toggleable()->visible(fn () => auth()->user()?->role?->canViewRawPaymentPayloads() ?? false),
            TextColumn::make('received_at')->dateTime(),
            TextColumn::make('processed_at')->dateTime(),
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
