<?php

namespace Modules\Page\App\Filament\Resources\PageResource\Forms;

use Filament\Forms\Components\Fieldset;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Grid;
use Filament\Models\Contracts\HasName;
use Modules\Page\Entities\Component;
use Modules\Page\App\Filament\Resources\PageResource\Forms\Components\FieldGenerator;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Page\Entities\Page;

class PageForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Thông tin trang')
                ->schema([
                    TextInput::make('title')
                        ->label(__('page::page.form.label.title'))
                        ->placeholder(__('page::page.form.placeholder.title'))
                        ->required()
                        ->columnSpan(6),
                        
                    TextInput::make('seo_title')
                        ->label(__('page::page.form.label.seo_title'))
                        ->placeholder(__('page::page.form.placeholder.seo_title'))
                        ->id('post-seo-title')
                        ->maxLength(60)
                        ->rules(['max:60'])
                        ->columnSpan(6)
                        ->helperText('Tối đa 60 ký tự'),
                        
                    Textarea::make('seo_description')
                        ->label(__('page::page.form.label.seo_description')) 
                        ->placeholder(__('page::page.form.placeholder.seo_description'))
                        ->id('post-seo-description')
                        ->maxLength(160)
                        ->rules(['max:160'])
                        ->columnSpan(6)
                        ->helperText('Tối đa 160 ký tự'),
                        
                    TagsInput::make('seo_keywords')
                        ->label(__('page::page.form.label.seo_keywords'))
                        ->color('success')
                        ->placeholder(__('page::page.form.placeholder.seo_keywords'))
                        ->suggestions(
                            Page::pluck('seo_keywords')
                                ->filter()
                                ->unique()
                                ->flatMap(fn($keywords) => explode(',', $keywords))
                                ->toArray()
                        )
                        ->rules(['max:255'])
                        ->separator(',')
                        ->columnSpan(6),
                ])->columns(12),
                Section::make(__('page::page.form.section.content'))
                    ->schema([
                        Repeater::make('components')
                            ->label('')
                            ->schema([
                                Select::make('component_id')
                                    ->label(__('page::page.form.label.component_id'))
                                    ->required()
                                    ->options(function () {
                                        return Component::all()->pluck('label', 'id');
                                    })
                                    ->reactive()
                                    ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                        $componentId = $get('component_id');

                                        $set('configurations', []);

                                        if ($componentId) {
                                            $fields = self::getComponentFields($componentId);
                                            self::resetFields($fields, $set);
                                        }
                                    }),
                                Grid::make(1)
                                    ->schema(fn(Get $get): array => self::getComponentFields($get('component_id')))
                                    ->hidden(fn(Get $get): bool => ! $get('component_id'))
                            ])
                            ->extraAttributes(['style' => 'overflow: visible'])
                            ->collapsed()
                            ->reorderableWithButtons()
                            ->orderColumn('order')
                            ->itemLabel(fn(array $state): ?string => Component::find($state['component_id'])?->label ?? null)
                            ->collapsible()
                            ->addActionLabel(__('page::page.form.action.add_component'))
                    ])
            ]);
    }

    protected static function resetFields(array $fields, callable $set): void
    {
        foreach ($fields as $field) {
            // Nếu component có children (Section, Fieldset, Tabs, Repeater, Grid)
            if (method_exists($field, 'getChildComponents')) {
                self::resetFields($field->getChildComponents(), $set);
            }

            // Chỉ reset nếu component có name (implements HasName)
            if ($field instanceof HasName) {
                $set($field->getName(), null);
            }
        }
    }

    protected static function getComponentFields($componentId): array
    {
        if (!$componentId) {
            return [];
        }

        $component = Component::with(['configurations'])->find($componentId);

        if (!$component) {
            return [];
        }

        $configFields = (new FieldGenerator())->generateFields($component->configurations);

        return array_merge($configFields);
    }
}
