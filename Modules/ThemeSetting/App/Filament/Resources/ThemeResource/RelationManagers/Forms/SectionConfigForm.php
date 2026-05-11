<?php

namespace Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Forms;

use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Illuminate\Support\HtmlString;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums\FieldInputType;

class SectionConfigForm
{
    protected static array $basicFields = [
        FieldInputType::COLOR->value,
        FieldInputType::TEXT->value,
        FieldInputType::NUMBER->value,
        FieldInputType::SELECT->value,
        FieldInputType::TOGGLE->value,
        FieldInputType::MENU_SELECTION->value,
    ];

    public static function getFormSchema($record): array
    {

        $configs = $record->sectionCfgs()
            ->orderBy('group_name')
            ->get()
            ->groupBy('group_name');

        return collect($configs)
            ->map(fn($groupConfigs, $groupName) => static::createConfigGroup($groupName, $groupConfigs))
            ->values()
            ->toArray();
    }

    protected static function createConfigGroup(string $groupName, $configs): Section
    {
        return Section::make($groupName ?: 'Cấu hình chung')
            ->description(fn() => new HtmlString(static::getGroupDescription($groupName)))
            ->schema(static::createConfigFields($configs))
            ->columns(['default' => 1, 'lg' => 2])
            ->collapsed()
            ->collapsible();
    }

    protected static function createConfigFields($configs): array
    {
        return $configs->map(fn($config) => static::createField($config))->toArray();
    }

    protected static function createField($config): Component
    {
        $fieldType = FieldInputType::fromString($config->field_type);
        $field = $fieldType->createField($config);

        if (static::isBasicField($config->field_type)) {
            $field->columnSpan(1);
        } else {
            $field->columnSpan(['lg' => 2]);
        }

        return $field;
    }

    protected static function isBasicField(string $fieldType): bool
    {
        return in_array($fieldType, static::$basicFields);
    }

    protected static function getGroupDescription(string $groupName): string
    {
        return "
            <div class='text-sm text-gray-500'>
                Cấu hình cho " . strtolower($groupName ?: 'chung') . "
            </div>
        ";
    }
}
