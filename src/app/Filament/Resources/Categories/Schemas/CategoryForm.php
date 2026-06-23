<?php

namespace App\Filament\Resources\Categories\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
            Textarea::make('description')->columnSpanFull(),
            FileUpload::make('banner_image_path')->label('Banner image')->image()->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048)->disk('public')->directory('category-banners')->visibility('public'),
            TextInput::make('sort_order')->required()->integer()->default(0),
            Toggle::make('is_active')->default(true),
        ]);
    }
}
