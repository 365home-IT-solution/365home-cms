<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\ThemeSetting\App\Filament\Resources\ThemeResource\RelationManagers\Enums\FieldInputType;

class ConfigSection
{
    public static function make(): Section
    {
        return Section::make('Cấu hình')
            ->description('Cài đặt cấu hình cho section')
            ->icon('heroicon-m-cog-6-tooth')
            ->collapsible()
            ->collapsed()
            ->schema([
                self::ConfigRepeater()
            ]);
    }

    private static function ConfigRepeater(): Repeater
    {
        return Repeater::make('sectionCfgs')
            ->relationship('sectionCfgs')
            ->label('')
            ->addActionLabel('Thêm cấu hình mới')
            ->schema([
                Grid::make(3)
                    ->schema([
                        TextInput::make('key')
                            ->label('Key')
                            ->required(),
                        TextInput::make('label')
                            ->label('Label')
                            ->required(),
                            Select::make('field_type')
                            ->label('Loại trường')
                            ->options(collect(FieldInputType::cases())
                                ->mapWithKeys(fn($type) => [$type->value => str($type->value)->title()]))
                            ->reactive()
                            ->live()
                            ->required(),
                        TextInput::make('group_name')
                            ->label('Tên nhóm'),
                        TextInput::make('default_value')
                            ->label('Giá trị mặc định'),

                        TextInput::make('min_value')
                            ->numeric()
                            ->visible(
                                fn(Get $get) =>
                                $get('field_type') === FieldInputType::NUMBER->value
                            )
                            ->label('Giá trị nhỏ nhất'),

                        TextInput::make('max_value')
                            ->numeric()
                            ->visible(
                                fn(Get $get) =>
                                $get('field_type') === FieldInputType::NUMBER->value
                            )
                            ->label('Giá trị lớn nhất'),

                        TextInput::make('suffix_value')
                            ->visible(
                                fn(Get $get) =>
                                $get('field_type') === FieldInputType::NUMBER->value
                            )
                            ->label('Hậu tố'),

                        Textarea::make('help_text')
                            ->columnSpanFull()
                            ->rows(3)
                            ->label('Văn bản hướng dẫn'),
                    ]),
                self::OptionRepeater()
            ])
            ->itemLabel(fn(array $state): ?string => $state['key'] ?? 'Cấu hình mới')
            ->collapsible()
            ->collapsed()
            ->columnSpanFull();
    }

    private static function OptionRepeater(): Repeater
    {
        return Repeater::make('sectionOpts')
            ->relationship('sectionOpts')
            ->label('')
            ->addActionLabel('Thêm tùy chọn')
            ->schema([
                Grid::make(2)
                    ->schema([
                        TextInput::make('option')
                            ->label('label')
                            ->required(),
                        TextInput::make('value')
                            ->label('Giá trị')
                            ->required(),
                    ])
            ])
            ->visible(
                fn(Get $get) =>
                $get('field_type') === FieldInputType::SELECT->value
            )
            ->itemLabel(fn(array $state): ?string => $state['option'] ?? 'Tùy chọn mới')
            ->collapsible()
            ->collapsed()
            ->columnSpanFull();
    }
}
