<?php

namespace Modules\BladeThemeV1\Enums;

use Modules\BladeThemeV1\Interfaces\HasEnumValues;
use Modules\BladeThemeV1\Traits\EnumValuesTrait;

enum HeaderSection: string implements HasEnumValues
{
    use EnumValuesTrait;

    case TOP_BAR = 'top_bar';
    case HEADER_MAIN = 'header_main';
    case LOGO = 'logo';
    case NAVIGATION_BAR = 'navigation_bar';
    case ACTIONS = 'header_action';
    case CART = 'cart_shop';
}
