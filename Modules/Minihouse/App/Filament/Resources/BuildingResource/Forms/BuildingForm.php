<?php

namespace Modules\Minihouse\App\Filament\Resources\BuildingResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;

class BuildingForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin toà nhà')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Tên toà nhà')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('address')
                        ->label('Địa chỉ')
                        ->maxLength(255),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
