<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms\Components;

use Filament\Forms\Components\FileUpload;
use Modules\Component\App\Enums\FieldInputType;
use TomatoPHP\FilamentIcons\Components\IconPicker;

class MediaFieldGenerator
{
    public function createFileUploadField($config): FileUpload
    {
        $field = FileUpload::make("config_values.{$config->id}")
            ->label($config->label);

        return match ($config->type_field) {
            FieldInputType::IMAGE_OR_VIDEO->value => $field->acceptedFileTypes(['image/*', 'video/*']),
            FieldInputType::IMAGE->value => $field->image(),
            FieldInputType::IMAGES->value => $field->multiple(),
            default => $field,
        };
    }

    public function createIconField($config): IconPicker
    {
        return IconPicker::make("config_values.{$config->id}")
            ->label($config->label)
            ->placeholder('Chọn biểu tượng...');
    }
}