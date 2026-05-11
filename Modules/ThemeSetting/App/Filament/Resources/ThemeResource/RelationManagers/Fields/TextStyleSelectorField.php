<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Radio;

class TextStyleSelectorField extends BaseField
{

    public function create(): Component
    {
        return $this->addCommonAttributes(
            Radio::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->options([
                    'light' => 'Light',
                    'dark' => 'Dark'
                ])
                ->descriptions([
                    'light' => 'Chữ tối trên nền sáng',
                    'dark' => 'Chữ sáng trên nền tối'
                ])
                ->extraAttributes([
                    'class' => 'p-4 border rounded-lg shadow-sm'
                ])
                ->inline()
                ->inlineLabel(false)
        );
    }
}
