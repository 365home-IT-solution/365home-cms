<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;

class ToggleField extends BaseField {
    public function create(): Component {
        return $this->addCommonAttributes(
            Toggle::make("config.{$this->config->key}")
                ->label($this->config->label)
        );
    }
}
