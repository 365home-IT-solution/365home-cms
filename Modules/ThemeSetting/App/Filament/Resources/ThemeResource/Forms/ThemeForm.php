<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class ThemeForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // TextInput::make('name')
                //     ->required()
                //     ->maxLength(255),
            ]);
    }
}
