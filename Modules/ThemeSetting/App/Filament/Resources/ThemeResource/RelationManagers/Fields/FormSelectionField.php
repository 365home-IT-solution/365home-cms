<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Select;
use Modules\Form\Entities\Form;

class FormSelectionField extends BaseField
{

    public function create(): Component {
        $form = Form::pluck('name', 'id')->toArray();

        return $this->addCommonAttributes(
            Select::make("config.{$this->config->key}")
                ->options($form)
                ->label($this->config->label)
        );
    }

}
