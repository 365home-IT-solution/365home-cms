<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\Components;

class DetailSection
{
    public static function make(): Section
    {
        return Section::make('Chi tiết')
            ->description('Mô tả chi tiết về theme')
            ->icon('heroicon-o-document-text')
            ->columns(1)
            ->schema([
                self::Description(),
                self::Introduction(),
            ]);
    }

    public static function Description(): Textarea
    {
        return Textarea::make('description')
            ->label('Mô tả')
            ->placeholder('Mô tả ngắn về theme...')
            ->rows(3);
    }

    public static function Introduction(): RichEditor
    {
        return RichEditor::make('introduction')
            ->label('Giới thiệu')
            ->placeholder('Nội dung chi tiết về theme...')
            ->toolbarButtons([
                'bold',
                'italic',
                'link',
                'bulletList',
                'orderedList',
            ]);
    }
}
