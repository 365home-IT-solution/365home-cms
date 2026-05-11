<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Textarea;

class TextareaField extends BaseField {

    public function create(): Component {
        return $this->addCommonAttributes(
            Textarea::make("config.{$this->config->key}")
                ->rows(3)
                ->placeholder($this->config->default_value)
        );
    }

}
