<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;

class UrlField
{
    public static function create(): TextInput
    {
        return TextInput::make('url')
            ->label('Đường dẫn')
            ->prefix('https://')
            ->placeholder(fn(Get $get): string =>
            SocialPlatform::from($get('platform') ?? SocialPlatform::FACEBOOK->value)->placeholder()
            )
            ->helperText(fn(Get $get) =>
            $get('platform')
                ? 'Nhập URL của trang ' . SocialPlatform::from($get('platform'))->label()
                : 'Chọn mạng xã hội và nhập URL'
            );
    }
}
