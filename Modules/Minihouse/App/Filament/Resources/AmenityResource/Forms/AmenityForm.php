<?php

namespace Modules\Minihouse\App\Filament\Resources\AmenityResource\Forms;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class AmenityForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin tiện ích')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Tên tiện ích')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    FileUpload::make('image')
                        ->label('Biểu tượng / hình ảnh')
                        ->image()
                        ->imageEditor()
                        ->imagePreviewHeight('100')
                        ->directory('minihouse/amenities')
                        ->disk('public')
                        ->nullable(),
                ]),
        ]);
    }
}
