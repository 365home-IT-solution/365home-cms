<?php

namespace Modules\BladeThemeV1\Enums;

use Modules\BladeThemeV1\Interfaces\HasEnumValues;
use Modules\BladeThemeV1\Traits\EnumValuesTrait;

enum ContactLinkSection: string implements HasEnumValues
{
    use EnumValuesTrait;

    case CONTACT_LINK_DETAIL = 'contact_button_detail';
}
