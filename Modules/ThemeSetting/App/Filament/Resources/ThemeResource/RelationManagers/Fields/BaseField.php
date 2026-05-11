<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Field;
use Filament\Forms\Components\Component;

abstract class BaseField
{
    protected $config;

    public function __construct($config)
    {
        $this->config = $config;
    }

    abstract public function create(): Component;

    protected function addCommonAttributes(Component $field): Component
    {
        return $field
            ->hintColor('gray')
            ->when(
                $this->config->help_text,
                fn($component) => $component->hintIcon('heroicon-m-information-circle', tooltip: $this->config->help_text)
            );
    }
}
