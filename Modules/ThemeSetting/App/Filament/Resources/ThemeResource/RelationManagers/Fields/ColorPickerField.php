<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Fields;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Component;

class ColorPickerField extends BaseField {

    public function create(): Component {
        return $this->addCommonAttributes(
            ColorPicker::make("config.{$this->config->key}")
                ->label($this->config->label)
                ->rules([
                    'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'
                ])
                ->validationMessages([
                    'regex' => 'Giá trị phải là mã màu hex hợp lệ (ví dụ: #FF0000)'
                ])
                ->placeholder("Chọn màu")
        );
    }

}
