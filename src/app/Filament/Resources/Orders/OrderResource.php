<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\EditOrder;
use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\RelationManagers\AuditLogsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\InventoryMovementsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\OrderItemsRelationManager;
use App\Filament\Resources\Orders\RelationManagers\PaymentsRelationManager;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [OrderItemsRelationManager::class, PaymentsRelationManager::class, InventoryMovementsRelationManager::class, AuditLogsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListOrders::route('/'), 'edit' => EditOrder::route('/{record}/edit')];
    }
}
