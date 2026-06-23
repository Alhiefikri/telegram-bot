<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Services\Admin\AdminOrderOperations;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_number')->searchable()->sortable(),
            TextColumn::make('customer.username')->label('Customer')->searchable(),
            TextColumn::make('order_status')->badge()->sortable(),
            TextColumn::make('payment_status')->badge()->sortable(),
            TextColumn::make('total_amount')->formatStateUsing(fn ($state) => Money::idr($state))->sortable(),
            TextColumn::make('expires_at')->dateTime()->sortable(),
        ])->recordActions([
            EditAction::make(),
            Action::make('completeManual')->label('Transaksi selesai')->authorize('completeManual')
                ->visible(fn ($record, AdminOrderOperations $operations) => $operations->canCompleteManualOrder($record))
                ->requiresConfirmation()
                ->action(fn ($record, AdminOrderOperations $operations) => $operations->completeManualOrder($record, auth()->user(), request()->ip())),
            Action::make('resendReceipt')->label('Resend receipt')->authorize('resendReceipt')
                ->visible(fn ($record, AdminOrderOperations $operations) => $operations->canResendReceipt($record))
                ->action(fn ($record, AdminOrderOperations $operations) => $operations->resendReceipt($record, auth()->user(), request()->ip())),
        ]);
    }
}
