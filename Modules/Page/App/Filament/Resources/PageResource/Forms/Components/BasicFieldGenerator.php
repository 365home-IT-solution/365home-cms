<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms\Components;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ColorPicker;

class BasicFieldGenerator
{
    public function createTextField($config): TextInput
    {
        return TextInput::make("config_values.{$config->id}")
            ->label($config->label)
            ->placeholder("Nhập " . strtolower($config->label) . " ...");
    }

    public function createNumberField($config): TextInput
    {
        return TextInput::make("config_values.{$config->id}")
            ->label($config->label)
            ->numeric()
            ->placeholder("Nhập " . strtolower($config->label) . " ...");
    }

    public function createTextareaField($config): Textarea
    {
        return Textarea::make("config_values.{$config->id}")
            ->label($config->label)
            ->rows(4)
            ->placeholder("Nhập " . strtolower($config->label) . " ...")
            ->columnSpanFull();
    }

    public function createToggleField($config): Toggle
    {
        return Toggle::make("config_values.{$config->id}")
            ->label($config->label)
            ->inline(false)
            ->helperText("Bật/Tắt " . strtolower($config->label) . " ...");
    }

    public function createColorPickerField($config): ColorPicker
    {
        return ColorPicker::make("config_values.{$config->id}")
            ->label($config->label)
            ->helperText("Chọn " . strtolower($config->label) . " ...");
    }

    public function createRangeField($config): TextInput
    {
        return TextInput::make("config_values.{$config->id}")
            ->label($config->label)
            ->numeric()
            ->placeholder('Nhập giá trị trong khoảng từ 0 đến 100')
            ->minValue(0)
            ->maxValue(100)
            ->step(1);
    }
}