<?php

namespace Modules\Minihouse\App\Filament\Resources\ZoneResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class ZoneForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin khu vực')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên khu vực')
                        ->required()
                        ->maxLength(255),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
