<?php

declare(strict_types=1);

namespace Modules\Menu\Enums;

use Filament\Support\Contracts\HasLabel;

enum LinkTarget: string implements HasLabel
{
    case Self = '_self';

    case Blank = '_blank';

    case Parent = '_parent';

    case Top = '_top';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Self => __('menu::menu-builder.open_in.options.self'),
            self::Blank => __('menu::menu-builder.open_in.options.blank'),
            self::Parent => __('menu::menu-builder.open_in.options.parent'),
            self::Top => __('menu::menu-builder.open_in.options.top'),
        };
    }
}
