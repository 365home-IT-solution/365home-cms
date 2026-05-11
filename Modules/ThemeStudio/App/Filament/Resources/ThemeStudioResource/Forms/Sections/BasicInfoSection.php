<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class BasicInfoSection
{
    public static function make(): Section
    {
        return Section::make('Thông tin cơ bản')
            ->description('Nhập các thông tin cơ bản của theme')
            ->icon('heroicon-o-information-circle')
            ->columns(2)
            ->schema([
                self::ThemeName(),
                self::DesignBy(),
                self::Version(),
                self::IsActive(),
            ]);
    }

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

    public static function IsActive(): Toggle
    {
        return Toggle::make('is_active')
            ->label('Kích hoạt')
            ->default(true)
            ->inline(false);
    }
}
