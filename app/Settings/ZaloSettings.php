<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class ZaloSettings extends Settings
{
    public ?string $access_token;
    public ?string $refresh_token;
    public ?int $access_token_expires_at;

    public static function group(): string
    {
        return 'zalo';
    }

    public static function encrypted(): array
    {
        return [
            'access_token',
            'refresh_token',
        ];
    }
}
