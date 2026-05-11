<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums;

enum ContentTypeEnum: string
{
    case TEXT = 'text';
    case IMAGE = 'image';
    case IFRAME = 'iframe';
    case SOCIAL_MEDIA = 'social_media';
    case GOOGLE_MAP = 'google_map';
    case MENU = 'menu';
    case BUSINESS = 'business';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function labels(): array
    {
        return [
            self::TEXT->value => 'Văn bản',
            self::IMAGE->value => 'Hình ảnh',
            self::IFRAME->value => 'iFrame',
            self::SOCIAL_MEDIA->value => 'Mạng xã hội',
            self::GOOGLE_MAP->value => 'Bản đồ Google',
            self::MENU->value => 'Menu',
            self::BUSINESS->value => 'Thông tin doanh nghiệp',
        ];
    }
}
