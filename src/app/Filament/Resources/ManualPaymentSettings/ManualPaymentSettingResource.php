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

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?string $navigationLabel = 'Link Toko & Pembayaran';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'Pengaturan Pembayaran';

    protected static ?string $pluralModelLabel = 'Pengaturan Pembayaran';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('payment_mode')
                ->label('Mode pembayaran')
                ->options(['manual' => 'Manual', 'pakasir' => 'Pakasir'])
                ->default('manual')
                ->required()
                ->helperText('Pilih Manual saat Pakasir belum aktif. Bot akan meminta bukti bayar dan menunggu persetujuan admin.'),
            TextInput::make('dana_account_name')->label('Nama akun DANA')->maxLength(255),
            TextInput::make('dana_account_number')->label('Nomor DANA')->maxLength(255),
            FileUpload::make('dana_qr_image_path')->label('Gambar QR DANA')->image()->disk('public')->directory('payments/dana')->visibility('public')->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
            TextInput::make('dana_qr_image_url')->label('URL QR DANA')->url()->maxLength(255),
            TextInput::make('admin_contact_url')->label('URL kontak admin')->url()->maxLength(255),
            TextInput::make('admin_username')->label('Username admin')->maxLength(255),
            TextInput::make('admin_telegram_chat_id')->label('Chat ID Telegram admin')->maxLength(255),
            Textarea::make('instructions')->label('Catatan pembayaran')->columnSpanFull(),
            Toggle::make('is_active')->label('Aktifkan pengaturan')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->sortable(),
            TextColumn::make('payment_mode')->label('Mode')->badge()->sortable(),
            TextColumn::make('dana_account_name')->label('Nama DANA')->searchable(),
            TextColumn::make('dana_account_number')->label('Nomor DANA')->searchable(),
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
