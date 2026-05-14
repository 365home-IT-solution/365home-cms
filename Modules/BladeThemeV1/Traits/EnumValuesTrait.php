<?php

namespace Modules\BladeThemeV1\Traits;

trait EnumValuesTrait 
{
    public static function values(): array 
    {
        return array_column(self::cases(), 'value');
    }
}