<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;

class NumberField extends BaseField {

    public function create(): Component {
        $input = TextInput::make("config.{$this->config->key}")
            ->numeric()
            ->label($this->config->label)
            ->placeholder($this->config->default_value);
     
        if ($this->config->min_value !== null) {
            $input->minValue($this->config->min_value);
        }
     
        if ($this->config->max_value !== null) {
            $input->maxValue($this->config->max_value);
        }
     
        if ($this->config->suffix_value) {
            $input->suffix($this->config->suffix_value); 
        }
     
        return $this->addCommonAttributes($input);
     }

}
