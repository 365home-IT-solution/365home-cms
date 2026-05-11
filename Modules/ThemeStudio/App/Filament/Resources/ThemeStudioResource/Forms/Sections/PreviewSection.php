<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Components;

class PreviewSection
{
    public static function make(): Section
    {
        return Section::make('Xem trước')
            ->description('Tải lên ảnh xem trước của theme')
            ->icon('heroicon-o-photo')
            ->schema([
                self::PreviewImage(),
            ]);
    }

    public static function PreviewImage(): FileUpload
    {
        return FileUpload::make('preview_image')
            ->label('Ảnh xem trước')
            ->image()
            ->imageResizeMode('cover')
            ->imageCropAspectRatio('16:9')
            ->directory('themes/previews');
    }
}
