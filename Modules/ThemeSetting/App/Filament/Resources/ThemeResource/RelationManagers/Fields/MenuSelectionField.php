<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Modules\Menu\Entities\Menu;

class MenuSelectionField extends BaseField
{
    public function create(): Component
    {
        $menu = Menu::where('is_visible', '1')->pluck('name', 'id')->toArray();

        return $this->addCommonAttributes(
            Select::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->options($menu)
        );
    }
}
