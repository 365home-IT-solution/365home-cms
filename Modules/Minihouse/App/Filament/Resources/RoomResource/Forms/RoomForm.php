<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Forms;

use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\RoomAsset;

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
                    TextInput::make('floor')
                        ->label('Tầng')
                        ->numeric()
                        ->helperText('Để trống nếu toà nhà không chia tầng — dùng để nhóm phòng trên sơ đồ ở Dashboard.'),
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
                            Room::STATUS_EMPTY    => 'Trống',
                            Room::STATUS_RESERVED => 'Đã đặt cọc',
                            Room::STATUS_RENTED   => 'Đã thuê',
                            Room::STATUS_REPAIR   => 'Đã khoá',
                        ])
                        ->default(Room::STATUS_EMPTY)
                        ->required()
                        ->helperText('"Đã đặt cọc"/"Đã thuê" tự động cập nhật theo Hợp đồng (ngày bắt đầu) — chỉ nên tự sửa tay khi cần điều chỉnh bất thường.'),
                    Textarea::make('note')
                        ->label('Ghi chú')
                        ->columnSpanFull(),
                ]),

            // Vị trí thật của phòng trên mặt bằng tầng (hàng/cột) — RoomOccupancyMapWidget ở
            // Dashboard vẽ sơ đồ đúng theo 2 số này thay vì xếp theo thứ tự mã phòng, để nhìn giống
            // bản vẽ mặt bằng thật (dãy phòng, hành lang...). Để trống thì phòng vẫn quản lý bình
            // thường, chỉ không lên được sơ đồ (rơi vào khu "Chưa gán vị trí").
            Section::make('Vị trí trên sơ đồ tầng')
                ->description('Khai báo 1 lần để phòng lên đúng ô trên Sơ đồ phòng ở Dashboard — VD dãy đầu hành lang là hàng 1, phòng thứ 2 từ trái là cột 2.')
                ->columns(2)
                ->schema([
                    TextInput::make('position_row')
                        ->label('Hàng')
                        ->numeric()
                        ->minValue(1)
                        ->live()
                        ->helperText('Đếm từ 1, theo chiều từ ngoài hành lang vào trong (hoặc trên xuống dưới).'),
                    TextInput::make('position_col')
                        ->label('Cột')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Đếm từ 1, theo chiều trái sang phải dọc hành lang.')
                        ->rule(fn (Get $get, ?Room $record): Closure => self::uniquePositionRule($get, $record)),
                ]),

            // Danh sách tài sản/nội thất CÓ SẴN trong phòng (tủ lạnh, máy lạnh, giường...) kèm tình
            // trạng — theo dõi để biết cần thay/sửa gì khi khách trả phòng, và làm căn cứ đối chiếu
            // khi phát sinh phụ thu hư hỏng. Chỉ hiện được SAU KHI phòng đã tạo (cần room_id để lưu
            // quan hệ) — lúc tạo mới phòng thì thêm tài sản ở lần sửa kế tiếp.
            Section::make('Tài sản trong phòng')
                ->description('Theo dõi tủ lạnh, máy lạnh, giường, tủ... và tình trạng hiện tại — dùng làm căn cứ khi khách trả phòng hoặc phát sinh phụ thu hư hỏng.')
                ->visible(fn (?Room $record) => $record !== null)
                ->schema([
                    Repeater::make('assets')
                        ->label('')
                        ->relationship('assets')
                        ->schema([
                            TextInput::make('name')
                                ->label('Tên tài sản')
                                ->required()
                                ->maxLength(255),
                            Select::make('condition')
                                ->label('Tình trạng')
                                ->options([
                                    RoomAsset::CONDITION_GOOD        => 'Tốt',
                                    RoomAsset::CONDITION_DAMAGED      => 'Hư hỏng',
                                    RoomAsset::CONDITION_MAINTENANCE => 'Đang sửa',
                                ])
                                ->default(RoomAsset::CONDITION_GOOD)
                                ->required(),
                            TextInput::make('note')
                                ->label('Ghi chú'),
                        ])
                        ->columns(3)
                        ->addActionLabel('Thêm tài sản')
                        ->defaultItems(0)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['name'] ?? null),
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

    // Chặn 2 phòng CÙNG toà nhà + CÙNG tầng trùng hàng/cột — sơ đồ chỉ vẽ được 1 phòng cho mỗi ô,
    // trùng vị trí sẽ khiến 1 trong 2 phòng biến mất khỏi sơ đồ mà không báo lỗi gì.
    private static function uniquePositionRule(Get $get, ?Room $record): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($get, $record) {
            $row = $get('position_row');
            $col = $get('position_col');

            if (blank($row) || blank($col)) {
                return;
            }

            $conflict = Room::query()
                ->where('building_id', $get('building_id'))
                ->where('floor', $get('floor'))
                ->where('position_row', $row)
                ->where('position_col', $col)
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($conflict) {
                $fail('Đã có phòng khác ở đúng vị trí (hàng ' . $row . ', cột ' . $col . ') của tầng này.');
            }
        };
    }
}
