<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields\SocialMedia;

enum SocialPlatform: string
{
    case FACEBOOK = 'facebook';
    case TWITTER = 'twitter';
    case INSTAGRAM = 'instagram';
    case YOUTUBE = 'youtube';
    case TIKTOK = 'tiktok';
    case LINKEDIN = 'linkedin';

    public function label(): string
    {
        return match ($this) {
            self::FACEBOOK => 'Facebook',
            self::TWITTER => 'Twitter',
            self::INSTAGRAM => 'Instagram',
            self::YOUTUBE => 'Youtube',
            self::TIKTOK => 'Tiktok',
            self::LINKEDIN => 'LinkedIn',
        };
    }

    public function icon(): string
    {
        return 'heroicon-o-globe-alt';
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::FACEBOOK => 'facebook.com/your.page',
            self::TWITTER => 'twitter.com/username',
            self::INSTAGRAM => 'instagram.com/username',
            self::YOUTUBE => 'youtube.com/c/channel',
            self::TIKTOK => 'tiktok.com/@username',
            self::LINKEDIN => 'linkedin.com/in/profile',
        };
    }
}
