<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Forms;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;

class TenantForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Hồ sơ khách thuê')
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
                        ->relationship('room', 'code')
                        ->searchable()
                        ->preload(),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),

            Section::make('Ảnh giấy tờ tuỳ thân')
                ->columns(2)
                ->schema([
                    FileUpload::make('id_card_front')
                        ->label('CCCD mặt trước')
                        ->image()
                        ->directory('minihouse/tenants')
                        ->disk('public'),
                    FileUpload::make('id_card_back')
                        ->label('CCCD mặt sau')
                        ->image()
                        ->directory('minihouse/tenants')
                        ->disk('public'),
                ]),
        ]);
    }
}
