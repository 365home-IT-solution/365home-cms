<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseCategoryResource\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class WarehouseCategoryForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Tên nhóm')
                ->placeholder('VD: Buồng phòng, Đồ uống, Văn phòng phẩm')
                ->required()
                ->maxLength(150),
        ]);
    }
}
