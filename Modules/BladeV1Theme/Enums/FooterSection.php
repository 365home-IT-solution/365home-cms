<?php

namespace Modules\BladeThemeV1\Enums;

use Modules\BladeThemeV1\Interfaces\HasEnumValues;
use Modules\BladeThemeV1\Traits\EnumValuesTrait;

enum FooterSection: string implements HasEnumValues
{
    use EnumValuesTrait;

    case FOOTER_MAIN = 'footer_main';
    case FOOTER_BOTTOM = 'footer_bottom';
}
