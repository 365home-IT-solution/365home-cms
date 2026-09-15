<?php

namespace Modules\Minihouse\App\Filament\Resources\AssetTypeResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class AssetTypeForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin loại tài sản')
                ->schema([
                    TextInput::make('name')
                        ->label('Tên loại tài sản')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255)
                        ->helperText('VD: Máy lạnh, Tủ lạnh, Giường, Tủ quần áo... — sẽ hiện trong danh sách chọn khi khai tài sản cho từng phòng.'),
                ]),
        ]);
    }
}
