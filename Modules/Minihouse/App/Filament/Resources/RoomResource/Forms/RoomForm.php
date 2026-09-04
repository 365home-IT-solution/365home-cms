<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Forms;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
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
                        ->relationship('building', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    TextInput::make('code')
                        ->label('Mã / Tên phòng')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('area')
                        ->label('Diện tích (m²)')
                        ->numeric()
                        ->suffix('m²'),
                    TextInput::make('price')
                        ->label('Giá thuê / tháng')
                        ->numeric()
                        ->required()
                        ->prefix('đ'),
                    Select::make('status')
                        ->label('Tình trạng')
                        ->options([
                            Room::STATUS_EMPTY  => 'Trống',
                            Room::STATUS_RENTED => 'Đã thuê',
                            Room::STATUS_REPAIR => 'Đang sửa',
                        ])
                        ->default(Room::STATUS_EMPTY)
                        ->required(),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),

            Section::make('Mô tả thêm')
                ->columns(1)
                ->schema([
                    FileUpload::make('photos')
                        ->label('Ảnh phòng')
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->directory('minihouse/rooms')
                        ->disk('public'),
                    // Chọn từ bảng minihouse_amenities (CRUD riêng ở "Tiện ích") thay vì danh sách cố
                    // định trong code — thêm/sửa/xoá tiện ích không cần đụng code.
                    Select::make('amenities')
                        ->label('Tiện ích đi kèm')
                        ->relationship('amenities', 'name')
                        ->multiple()
                        ->searchable()
                        ->preload(),
                ]),
        ]);
    }
}
