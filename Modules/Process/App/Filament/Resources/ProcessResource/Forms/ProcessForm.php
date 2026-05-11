<?php

declare(strict_types=1);

namespace Modules\Process\App\Filament\Resources\ProcessResource\Forms;

use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\FileUpload;

class ProcessForm
{
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('')
                    ->schema([
                        TextInput::make('name')
                            ->label(__('process::process.form.label.name'))
                            ->required()
                            ->placeholder(__('process::process.form.placeholder.name')),
                        Textarea::make('description')
                            ->label(__('process::process.form.label.description'))
                            ->placeholder(__('process::process.form.placeholder.description')),
                        Repeater::make('steps')
                            ->relationship('steps')
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('process::process.form.label.step_name'))
                                    ->placeholder(__('process::process.form.placeholder.step_name'))
                                    ->required(),
                                Textarea::make('description')
                                    ->label(__('process::process.form.label.step_description'))
                                    ->placeholder(__('process::process.form.placeholder.step_description'))
                                    ->nullable(),
                                FileUpload::make('icon')
                                    ->image()
                                    ->required()
                            ])
                            ->grid(3)
                            ->label(__('process::process.form.label.steps'))
                            ->collapsible()
                            ->orderColumn('order')
                            ->defaultItems(1)
                            ->addable(),
                    ])
            ]);
    }
}
