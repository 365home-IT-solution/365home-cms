<?php

namespace Modules\Themestudio\App\Filament\Resources\ThemeStudioResource\Forms\Sections;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use TomatoPHP\FilamentIcons\Components\IconPicker;
use Modules\ThemeStudio\App\Filament\Resources\ThemeStudioResource\Forms\ThemeStudioForm;

class SectionForm
{
    public static function make(): Section
    {
        return Section::make('Sections')
            ->description('Quản lý cấu trúc sections của theme')
            ->icon('heroicon-o-rectangle-stack')
            ->schema([
                Repeater::make('sections')
                    ->relationship(
                        'sections',
                        modifyQueryUsing: fn($query) => $query->whereNull('parent_id')
                    )
                    ->label('')
                    ->addActionLabel('Thêm mới sections')
                    ->mutateRelationshipDataBeforeCreateUsing(
                        fn(array $data) => array_merge($data, ['theme_id' => ThemeStudioForm::$theme?->id])
                    )
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Tên section')
                                    ->required()
                                    ->columnSpan(4),
                                TextInput::make('label')
                                    ->label('Label section')
                                    ->required()
                                    ->columnSpan(4),
                                IconPicker::make('icon')
                                    ->label('Icon')
                                    ->columnSpan(4),
                            ]),
                        Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(3)
                            ->columnSpanFull(),
                        self::SubSections(),
                    ])
                    ->itemLabel(fn(array $state): ?string => $state['name'] ?? 'Section mới')
                    ->collapsible()
                    ->collapsed()
                    ->columnSpanFull()
            ]);
    }

    private static function SubSections(): Section
    {
        return Section::make('Sub Sections')
            ->description('Các section con')
            ->icon('heroicon-m-arrow-trending-down')
            ->collapsible()
            ->collapsed()
            ->schema([
                Repeater::make('children')
                    ->label('')
                    ->addActionLabel('Thêm mới section con')
                    ->mutateRelationshipDataBeforeCreateUsing(
                        fn(array $data) => array_merge($data, ['theme_id' => ThemeStudioForm::$theme?->id])
                    )
                    ->relationship('children')
                    ->schema([
                        Grid::make(12)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Tên section')
                                    ->required()
                                    ->columnSpan(4),
                                TextInput::make('label')
                                    ->label('Label section')
                                    ->required()
                                    ->columnSpan(4),
                                IconPicker::make('icon')
                                    ->label('Icon')
                                    ->columnSpan(4),
                            ]),
                        Textarea::make('description')
                            ->label('Mô tả')
                            ->rows(3)
                            ->columnSpanFull(),
                        ConfigSection::make(),
                    ])
                    ->itemLabel(fn(array $state): ?string => $state['name'] ?? 'Section con mới')
                    ->collapsible()
                    ->collapsed()
            ]);
    }
}
