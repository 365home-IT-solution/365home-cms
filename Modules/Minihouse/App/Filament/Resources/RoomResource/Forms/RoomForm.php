<?php

namespace Modules\Minihouse\App\Filament\Resources\RoomResource\Forms;

use Closure;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Minihouse\App\Models\AssetType;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\RoomAsset;
use TomatoPHP\FilamentMediaManager\Form\MediaManagerInput;

// Bố cục 2 cột mirror App\Filament\Resources\ProductResource\Forms\ProductForm (Home) — cột trái
// (2/3) chứa thông tin nghiệp vụ chính, cột phải (1/3) chứa Video + Hình ảnh. KHÔNG mang theo field
// "Kiểu đặt phòng" (styles: theo khung giờ/theo ngày — chỉ có ý nghĩa với đặt phòng ngắn hạn của
// Home) và "Loại hình phòng" (room_type_id — MiniHouse CỐ ĐỊNH đúng 1 giá trị hệ thống để
// Product::booted() loại phòng khỏi luồng đặt phòng ngắn hạn, xem Room::booted() — để nhân viên tự
// đổi field này sẽ vô tình đẩy phòng ra khỏi phạm vi MiniHouse hoặc lọt vào luồng đặt phòng Home).
class RoomForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Grid::make(3)->schema([
                // ── Cột trái (2/3) ──────────────────────────────────────────
                Grid::make(1)->columnSpan(2)->schema([
                    self::classificationCard(),
                    self::basicInfoCard(),
                    self::positionCard(),
                    self::assetsCard(),
                ]),

                // ── Cột phải (1/3) ──────────────────────────────────────────
                Grid::make(1)->columnSpan(1)->schema([
                    self::videoCard(),
                    self::imagesCard(),
                ]),
            ]),
        ]);
    }

    // ── Phân loại: toà nhà, tiện ích ────────────────────────────────────────
    private static function classificationCard(): Section
    {
        return Section::make()->schema([
            Select::make('building_id')
                ->label('Toà nhà')
                ->relationship('building', 'name')
                ->searchable()
                ->preload()
                ->required(),

            // Chọn từ bảng minihouse_amenities (CRUD riêng ở "Tiện ích") thay vì danh sách cố định
            // trong code — thêm/sửa/xoá tiện ích không cần đụng code.
            Select::make('amenities')
                ->label('Tiện ích đi kèm')
                ->relationship('amenities', 'name')
                ->multiple()
                ->searchable()
                ->preload(),
        ])->columns(1);
    }

    // ── Thông tin cơ bản ────────────────────────────────────────────────────
    private static function basicInfoCard(): Section
    {
        return Section::make()->schema([
            TextInput::make('code')
                ->label('Mã / Tên phòng')
                ->required()
                ->maxLength(255)
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set) => $set('slug', Room::generateUniqueSlug($state ?: 'phong'))),

            TextInput::make('slug')
                ->label('Đường dẫn')
                ->required()
                ->unique(ignoreRecord: true)
                ->helperText('Bắt buộc để URL chi tiết phòng tự chuyển đúng dạng /loai-hinh/khu-vuc/chi-nhanh/phong')
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
                ->minValue(0)
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
                ->helperText('"Đã đặt cọc"/"Đã thuê" tự động cập nhật theo Hợp đồng (ngày bắt đầu) — chỉ nên tự sửa tay khi cần điều chỉnh bất thường.')
                // Chặn đổi sang "Trống"/"Đã khoá" trong khi phòng vẫn có hợp đồng "Đang hiệu
                // lực" — xem Room::hasActiveContract(). Vẫn cho phép GIỮ NGUYÊN "Đang thuê"/
                // "Đã đặt cọc" khi sửa các field khác của phòng (không đổi status thì không
                // kiểm tra).
                ->rule(fn (?Room $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                    if (
                        $record
                        && ! in_array($value, [Room::STATUS_RENTED, Room::STATUS_RESERVED], true)
                        && $record->hasActiveContract()
                    ) {
                        $fail('Phòng này đang có hợp đồng "Đang hiệu lực" — không thể đổi tình trạng thủ công. Hãy Thanh lý/Huỷ/Chuyển phòng ở trang Hợp đồng trước.');
                    }
                }),

            TextInput::make('address')
                ->label('Địa chỉ')
                ->maxLength(100)
                ->placeholder('Để trống thì dùng chung địa chỉ toà nhà'),

            TextInput::make('latitude')
                ->label('Vĩ độ (Latitude)')
                ->numeric()
                ->placeholder('10.7769'),

            TextInput::make('longitude')
                ->label('Kinh độ (Longitude)')
                ->numeric()
                ->placeholder('106.7009'),

            TextInput::make('map_url')
                ->label('Link Google Maps')
                ->url()
                ->maxLength(500)
                ->placeholder('https://maps.app.goo.gl/...')
                ->helperText('Link Google Maps trực tiếp đến địa chỉ phòng'),

            TextInput::make('hotline')
                ->label('Hotline liên hệ')
                ->maxLength(255),

            Textarea::make('note')
                ->label('Ghi chú')
                ->columnSpanFull(),
        ])->columns(2);
    }

    // Vị trí thật của phòng trên mặt bằng tầng (hàng/cột) — RoomOccupancyMapWidget ở
    // Dashboard vẽ sơ đồ đúng theo 2 số này thay vì xếp theo thứ tự mã phòng, để nhìn giống
    // bản vẽ mặt bằng thật (dãy phòng, hành lang...). Để trống thì phòng vẫn quản lý bình
    // thường, chỉ không lên được sơ đồ (rơi vào khu "Chưa gán vị trí").
    private static function positionCard(): Section
    {
        return Section::make('Vị trí trên sơ đồ tầng')
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
            ]);
    }

    // Danh sách tài sản/nội thất CÓ SẴN trong phòng (tủ lạnh, máy lạnh, giường...) kèm tình
    // trạng — theo dõi để biết cần thay/sửa gì khi khách trả phòng, và làm căn cứ đối chiếu
    // khi phát sinh phụ thu hư hỏng. Chỉ hiện được SAU KHI phòng đã tạo (cần room_id để lưu
    // quan hệ) — lúc tạo mới phòng thì thêm tài sản ở lần sửa kế tiếp.
    private static function assetsCard(): Section
    {
        return Section::make('Tài sản trong phòng')
            ->description('Theo dõi tủ lạnh, máy lạnh, giường, tủ... và tình trạng hiện tại — dùng làm căn cứ khi khách trả phòng hoặc phát sinh phụ thu hư hỏng.')
            ->visible(fn (?Room $record) => $record !== null)
            ->schema([
                Repeater::make('assets')
                    ->label('')
                    ->relationship('assets')
                    ->schema([
                        // Chọn từ danh mục "Loại tài sản" (CRUD riêng ở AssetTypeResource) thay
                        // vì gõ tay tự do — nhiều phòng thì gõ tay rất chậm và dễ lệch tên cùng 1
                        // loại (VD "Máy lạnh"/"máy lạnh"/"Điều hoà"). createOptionForm cho thêm
                        // NGAY 1 loại mới vào danh mục nếu chưa có, không cần rời trang Phòng —
                        // cùng nguyên tắc popup "thêm nhanh khách thuê" ở ContractForm.
                        Select::make('name')
                            ->label('Tên tài sản')
                            ->options(fn () => AssetType::orderBy('name')->pluck('name', 'name'))
                            ->searchable()
                            ->required()
                            ->createOptionForm([
                                TextInput::make('name')
                                    ->label('Tên loại tài sản')
                                    ->required()
                                    ->unique('minihouse_asset_types', 'name')
                                    ->maxLength(255),
                            ])
                            ->createOptionUsing(fn (array $data) => AssetType::create($data)->name),
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
            ]);
    }

    // ── Video ───────────────────────────────────────────────────────────────
    private static function videoCard(): Section
    {
        return Section::make()
            ->schema([
                TextInput::make('setting_video_room.url')
                    ->label('URL Video')
                    ->placeholder('https://www.youtube.com/watch?v=...')
                    ->helperText('Hỗ trợ: YouTube · Shorts · TikTok · Facebook · .mp4')
                    ->maxLength(1000),

                ToggleButtons::make('setting_video_room.ratio')
                    ->label('Tỉ lệ khung hình')
                    ->options([
                        '16:9' => '16:9 Ngang',
                        '9:16' => '9:16 Dọc',
                        '4:3'  => '4:3 Cổ điển',
                    ])
                    ->default('16:9')
                    ->grouped()
                    ->colors(['16:9' => 'info', '9:16' => 'success', '4:3' => 'warning']),

                TextInput::make('setting_video_room.title')
                    ->label('Tiêu đề video')
                    ->placeholder('VD: Phòng đơn tầng 2 - Toà A')
                    ->maxLength(200),
            ])
            ->columns(1);
    }

    // ── Hình ảnh ────────────────────────────────────────────────────────────
    // Cùng bộ chọn ảnh của Home (MediaManagerInput — Spatie MediaLibrary, collection "Ảnh bìa"/
    // "Thư viện" khai sẵn trên Product, Room kế thừa). Ảnh bìa KHÔNG bắt buộc như Home: phòng
    // MiniHouse tạo từ trước chưa có ảnh nào trong thư viện, bắt buộc sẽ chặn sửa các phòng đó.
    private static function imagesCard(): Section
    {
        return Section::make()->schema([
            MediaManagerInput::make('Ảnh bìa')
                ->label('Ảnh bìa')
                ->maxSize(3072)
                ->schema([])
                ->defaultItems(0)
                ->minItems(0)
                ->maxItems(1)
                ->columnSpanFull()
                ->inlineLabel(),

            MediaManagerInput::make('Thư viện')
                ->label('Thư viện ảnh')
                ->schema([])
                ->defaultItems(0)
                ->minItems(0)
                ->grid(2)
                ->maxItems(8)
                ->columnSpanFull(),
        ])->columns(1);
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

            // floor/position_row/position_col KHÔNG PHẢI cột thật trên products (uỷ quyền qua bảng
            // phụ minihouse_room_details, xem Room::getAttribute()) — lọc qua whereHas('detail', ...).
            $conflict = Room::query()
                ->where('building_id', $get('building_id'))
                ->whereHas('detail', fn ($q) => $q
                    ->where('floor', $get('floor'))
                    ->where('position_row', $row)
                    ->where('position_col', $col))
                ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->exists();

            if ($conflict) {
                $fail('Đã có phòng khác ở đúng vị trí (hàng ' . $row . ', cột ' . $col . ') của tầng này.');
            }
        };
    }
}
