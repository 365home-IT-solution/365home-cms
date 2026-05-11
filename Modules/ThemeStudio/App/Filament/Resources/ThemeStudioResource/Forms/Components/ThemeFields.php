<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms\Components;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;

class ThemeFields
{
    public static function ThemeName(): TextInput
    {
        return TextInput::make('name')
            ->label('Tên theme')
            ->placeholder('Nhập tên theme...')
            ->required()
            ->maxLength(255);
    }

    public static function DesignBy(): TextInput
    {
        return TextInput::make('design_by')
            ->label('Thiết kế bởi')
            ->placeholder('Tên người thiết kế...');
    }

    public static function Version(): TextInput
    {
        return TextInput::make('version')
            ->label('Phiên bản')
            ->placeholder('1.0.0')
            ->required();
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

    public static function PreviewImage(): FileUpload
    {
        return FileUpload::make('preview_image')
            ->label('Ảnh xem trước')
            ->image()
            ->imageResizeMode('cover')
            ->imageCropAspectRatio('16:9')
            ->directory('themes/previews');
    }

    public static function IsActive(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Kích hoạt')
            ->default(true)
            ->inline(false);
    }
}
