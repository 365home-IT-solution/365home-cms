<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Forms;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Modules\Minihouse\App\Models\Room;

class TenantForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin khách thuê')
                ->columns(2)
                ->schema([
                    TextInput::make('fullname')
                        ->label('Họ tên')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->maxLength(20),
                    TextInput::make('id_card_number')
                        ->label('Số CCCD/CMND')
                        ->maxLength(20),
                    Select::make('room_id')
                        ->label('Phòng đang ở')
                        ->options(fn () => Room::query()->pluck('code', 'id'))
                        ->searchable(),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
