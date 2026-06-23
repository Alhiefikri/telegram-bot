<?php

namespace App\Filament\Resources\Payments\Tables;

use App\Services\Admin\AdminPaymentOperations;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([TextColumn::make('provider')->sortable(), TextColumn::make('method'), TextColumn::make('merchant_ref')->searchable(), TextColumn::make('provider_reference')->searchable(), TextColumn::make('amount')->money('IDR', divideBy: 100)->sortable(), TextColumn::make('status')->badge()->sortable(), TextColumn::make('paid_at')->dateTime()->sortable(), TextColumn::make('expired_at')->dateTime()->sortable()])->recordActions([EditAction::make(), Action::make('reconcile')->label('Reconcile')->authorize('reconcile')->visible(fn ($record, AdminPaymentOperations $operations) => $operations->canReconcile($record))->action(fn ($record, AdminPaymentOperations $operations) => $operations->reconcile($record, auth()->user(), request()->ip()))]);
    }
}
