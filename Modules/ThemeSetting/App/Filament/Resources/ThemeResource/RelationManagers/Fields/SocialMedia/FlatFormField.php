<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia;

use Filament\Forms\Components\Select;
use Filament\Forms\Get;

class FlatFormField
{
    public static function create(): Select
    {
        return Select::make('platform')
            ->label('Mạng xã hội')
            ->options(collect(SocialPlatform::cases())->mapWithKeys(fn($platform) => [$platform->value => $platform->label()]))
            ->default(SocialPlatform::FACEBOOK->value)
            ->live()
            ->prefixIcon(fn(Get $get): string =>
            SocialPlatform::from($get('platform') ?? SocialPlatform::FACEBOOK->value)->icon()
            );
    }
}
