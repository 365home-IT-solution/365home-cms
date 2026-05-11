<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;

class TextField extends BaseField
{

    public function create(): Component
    {
        return $this->addCommonAttributes(
            TextInput::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->placeholder($this->config->default_value)
        );
    }
}
