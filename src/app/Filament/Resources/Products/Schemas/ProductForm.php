<?php

namespace App\Filament\Resources\Products\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
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
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->required()->columnSpanFull(),
                TextInput::make('price_amount')->label('Price')->required()->integer()->minValue(0),
                TextInput::make('currency')->required()->length(3)->default('IDR'),
                FileUpload::make('image_path')
                    ->label('Product image')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(2048)
                    ->disk('public')
                    ->directory('products')
                    ->visibility('public'),
                Toggle::make('is_active')->default(true),
                Placeholder::make('stock')->content(fn ($record) => $record?->stock ?? 0),
                Placeholder::make('reserved_stock')->content(fn ($record) => $record?->reserved_stock ?? 0),
            ]);
    }
}
