<?php

declare(strict_types=1);

namespace Modules\Warehouse\App\Filament\Resources\WarehouseUnitResource\Forms;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;

class WarehouseUnitForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('name')
                ->label('Tên đơn vị tính')
                ->placeholder('VD: cái, hộp, chai, kg')
                ->required()
                ->maxLength(50),
        ]);
    }
}
