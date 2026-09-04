<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Room;

class RoomForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin phòng')
                ->columns(2)
                ->schema([
                    Select::make('building_id')
                        ->label('Toà nhà')
                        ->options(fn () => Building::query()->pluck('name', 'id'))
                        ->searchable()
                        ->required(),
                    TextInput::make('code')
                        ->label('Mã / Tên phòng')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('area')
                        ->label('Diện tích (m²)')
                        ->numeric(),
                    TextInput::make('price')
                        ->label('Giá thuê / tháng')
                        ->numeric()
                        ->required()
                        ->prefix('đ'),
                    Select::make('status')
                        ->label('Trạng thái')
                        ->options([
                            Room::STATUS_EMPTY  => 'Trống',
                            Room::STATUS_RENTED => 'Đang thuê',
                            Room::STATUS_REPAIR => 'Bảo trì',
                        ])
                        ->default(Room::STATUS_EMPTY)
                        ->required(),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
