<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->label('Nama produk')->required()->maxLength(255),
                Select::make('category_id')->label('Kategori')->relationship('category', 'name')->searchable()->preload()->nullable(),
                Textarea::make('description')->label('Deskripsi singkat')->required()->columnSpanFull(),
                TextInput::make('price_amount')->label('Harga')->required()->integer()->minValue(0)->helperText('Masukkan angka saja, tanpa pemisah.'),
                TextInput::make('currency')->label('Mata uang')->required()->length(3)->default('IDR'),
                FileUpload::make('image_path')
                    ->label('Gambar produk')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public'),
                Toggle::make('is_active')->label('Aktifkan produk')->default(true),
                Placeholder::make('stock')->label('Stok saat ini')->content(fn ($record) => $record?->stock ?? 0),
                Placeholder::make('reserved_stock')->label('Stok tertahan')->content(fn ($record) => $record?->reserved_stock ?? 0),
            ]);
    }
}
