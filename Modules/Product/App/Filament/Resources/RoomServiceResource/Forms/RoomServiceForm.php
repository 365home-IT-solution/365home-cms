<?php

declare(strict_types=1);

namespace Modules\Product\App\Filament\Resources\RoomServiceResource\Forms;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Modules\Product\App\Models\Product;

class RoomServiceForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Select::make('product_id')
                ->label('Phòng')
                ->options(fn () => Product::where('is_activated', true)->pluck('name', 'id')->toArray())
                ->searchable()
                ->required(),

            TextInput::make('name')
                ->label('Tên dịch vụ')
                ->placeholder('VD: Coffee, Nước suối, Khăn tắm thêm')
                ->required()
                ->maxLength(255),

            Textarea::make('description')
                ->label('Mô tả')
                ->placeholder('Mô tả chi tiết dịch vụ')
                ->rows(3)
                ->maxLength(1000),

            TextInput::make('price')
                ->label('Giá')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->suffix('VND'),

            TextInput::make('unit')
                ->label('Đơn vị')
                ->placeholder('VD: người/ngày, lần, ly')
                ->maxLength(100),

            TextInput::make('sort_order')
                ->label('Thứ tự')
                ->numeric()
                ->default(0)
                ->minValue(0),
        ]);
    }
}
