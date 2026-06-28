<?php

namespace App\Filament\Resources\ManualPaymentSettings;

use App\Filament\Resources\ManualPaymentSettings\Pages\CreateManualPaymentSetting;
use App\Filament\Resources\ManualPaymentSettings\Pages\EditManualPaymentSetting;
use App\Filament\Resources\ManualPaymentSettings\Pages\ListManualPaymentSettings;
use App\Models\ManualPaymentSetting;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ManualPaymentSettingResource extends Resource
{
    protected static ?string $model = ManualPaymentSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Payment Settings';

    protected static ?string $modelLabel = 'Payment Setting';

    protected static ?string $pluralModelLabel = 'Payment Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payment_mode')
                ->label('Payment mode')
                ->options(['manual' => 'Manual approval', 'pakasir' => 'Pakasir'])
                ->default('manual')
                ->required()
                ->helperText('Pilih Manual selama Pakasir/KYC belum siap. Bot akan meminta screenshot /payment dan admin approve manual.'),
            TextInput::make('dana_account_name')->label('DANA account name')->maxLength(255),
            TextInput::make('dana_account_number')->label('DANA account number')->maxLength(255),
            FileUpload::make('dana_qr_image_path')->label('DANA QR image')->image()->disk('public')->directory('payments/dana')->visibility('public')->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
            TextInput::make('dana_qr_image_url')->label('External DANA QR URL')->url()->maxLength(255),
            TextInput::make('admin_contact_url')->label('Admin contact URL')->url()->maxLength(255),
            TextInput::make('admin_username')->label('Admin username')->maxLength(255),
            TextInput::make('admin_telegram_chat_id')->label('Admin Telegram chat ID')->maxLength(255),
            Textarea::make('instructions')->columnSpanFull(),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('payment_mode')->label('Mode')->badge()->sortable(),
            TextColumn::make('dana_account_name')->label('DANA name')->searchable(),
            TextColumn::make('dana_account_number')->label('DANA number')->searchable(),
            TextColumn::make('admin_username')->searchable(),
            IconColumn::make('is_active')->boolean()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListManualPaymentSettings::route('/'), 'create' => CreateManualPaymentSetting::route('/create'), 'edit' => EditManualPaymentSetting::route('/{record}/edit')];
    }
}
