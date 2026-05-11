<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;

class SelectField extends BaseField {

    public function create(): Component {
        return $this->addCommonAttributes(
            Select::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->options($this->config->sectionOpts()->pluck('option', 'value'))
        );
    }
}
