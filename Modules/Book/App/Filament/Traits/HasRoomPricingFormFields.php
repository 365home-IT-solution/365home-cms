<?php

namespace Modules\Book\App\Filament\Traits;

use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Product\App\Models\TimeSlot;

/**
 * Field/schema Filament dùng chung cho giá phòng — tách ra từ
 * Modules/Book/App/Filament/Resources/BookResource/Pages/SettingBook.php (trang "Hệ thống giá",
 * sửa trực tiếp Product/RoomTimeSlot = bảng giá mặc định) để Resource "Bảng giá" (sửa PriceBoardItem)
 * dùng lại ĐÚNG 1 bộ field/giao diện, không tạo UI mới lệch theme.
 *
 * $prefix: tiền tố statePath — 'room_123.' khi dùng trực tiếp trên form (SettingBook), hoặc '' khi
 * field đã nằm trong 1 Repeater tự khoanh vùng statePath riêng (PriceBoardResource).
 * $extraInfoFields: field bổ sung chèn đầu Section "Thông tin Phòng"/"Thiết lập chung" — SettingBook
 * dùng để nhét Hidden(product_id)/TextInput(product_name) riêng của nó.
 */
trait HasRoomPricingFormFields
{
    /** "Theo Khung Giờ" (styles=1): thông tin phòng + khung giờ/giá/khuyến mãi.
     *
     *  $baselinePriceResolver: khi truyền vào, thêm cột "Giá gốc" (chỉ đọc) ngay cạnh cột "Giá" —
     *  CHỈ dùng ở PriceBoardForm (đang sửa 1 bảng giá đặt tên) để admin thấy giá đang chạy thật/giá ở
     *  "Bảng giá mặc định" khi cân nhắc đổi giá mới, KHÔNG truyền ở SettingBook (trang "Hệ thống giá"
     *  = chính đang sửa bảng mặc định, không có "gốc" nào khác để so sánh) — giữ nguyên giao diện
     *  trang đó như cũ. Callable nhận (?string $productId, ?string $timeslotId): ?int.
     */
    protected static function slotPricingSchema(string $prefix, bool $isSuperAdmin, callable $promotionOptions, array $extraInfoFields = [], ?\Closure $baselinePriceResolver = null): array
    {
        $schema = [
            Section::make('Thông tin Phòng')
                ->schema([
                    ...$extraInfoFields,

                    TextInput::make($prefix . 'full_booking_discount')
                        ->label('Giảm giá khi đặt Full phòng')
                        ->placeholder('VD: 10% hoặc 50000')
                        ->helperText('Nhập % (VD: 10%) hoặc số tiền cố định (VD: 50000)')
                        ->maxLength(50),

                    TextInput::make($prefix . 'room_config_max_free_guests')
                        ->label('Số khách tối đa không phụ thu')
                        ->numeric()
                        ->default(2)
                        ->minValue(1)
                        ->suffix('người')
                        ->helperText('Từ người thứ (N+1) trở đi sẽ tính phụ thu.')
                        ->extraInputAttributes(['inputmode' => 'numeric']),

                    TextInput::make($prefix . 'room_config_extra_guest_fee')
                        ->label('Phụ thu mỗi người vượt ngưỡng')
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->suffix('đ/người')
                        ->helperText('0 = không phụ thu. Chỉ áp dụng cho đặt theo giờ.')
                        ->extraInputAttributes(['inputmode' => 'numeric']),

                    Repeater::make($prefix . 'bulk_discount_rules')
                        ->label('Giảm giá theo số khung giờ')
                        ->helperText('Cấu hình % giảm khi khách chọn nhiều khung giờ. Ví dụ: 2 khung → 5%, 3 khung → 10%.')
                        ->schema([
                            TextInput::make('slots')
                                ->label('Số khung giờ')
                                ->numeric()
                                ->minValue(2)
                                ->maxValue(99)
                                ->required()
                                ->suffix('khung')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TextInput::make('discount')
                                ->label('Giảm')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->required()
                                ->suffix('%')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Thêm mức giảm')
                        ->reorderableWithButtons()
                        ->orderColumn('slots')
                        ->columnSpanFull(),
                ])->columns(2),
        ];

        if ($isSuperAdmin) {
            $schema[] = Section::make('Khung giờ, giá, Thiết lập khuyến mãi')
                ->schema([
                    TableRepeater::make($prefix . 'roomTimeSlots')
                        ->headers([
                            Header::make('timeslot_id')->label('Khung giờ')->width('200px'),
                            ...($baselinePriceResolver ? [Header::make('_baseline_price')->label('Giá gốc')->width('150px')] : []),
                            Header::make('price')->label('Giá')->width('150px'),
                            Header::make('promotions')->label('Khuyến mãi')->width('250px'),
                            Header::make('over_night')->label('Qua đêm')->width('100px'),
                        ])
                        ->schema([
                            Hidden::make('id'),

                            Select::make('timeslot_id')
                                ->label('Khung giờ')
                                ->options(TimeSlot::all()->pluck('label', 'id'))
                                ->preload()
                                ->searchable()
                                ->createOptionForm([
                                    TextInput::make('label')->required(),
                                    TextInput::make('start_time')
                                        ->label('Giờ bắt đầu')
                                        ->required()
                                        ->placeholder('07:50')
                                        ->helperText('Định dạng HH:MM (VD: 07:50)')
                                        ->rules(['regex:/^\d{1,2}:\d{2}$/']),
                                    TextInput::make('end_time')
                                        ->label('Giờ kết thúc')
                                        ->required()
                                        ->placeholder('10:40')
                                        ->helperText('Định dạng HH:MM (VD: 10:40)')
                                        ->rules(['regex:/^\d{1,2}:\d{2}$/']),
                                ])
                                ->createOptionUsing(fn (array $data) => TimeSlot::create(array_merge($data, ['type' => 'time']))->id)
                                ->required()
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set, Get $get) use ($baselinePriceResolver) {
                                    if (! $baselinePriceResolver) {
                                        return;
                                    }

                                    $set('_baseline_price', $baselinePriceResolver($get('../../product_id'), $state));
                                }),

                            ...($baselinePriceResolver ? [
                                TextInput::make('_baseline_price')
                                    ->label('Giá gốc')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->suffix('VNĐ')
                                    ->helperText('Giá đang lưu ở Bảng giá mặc định'),
                            ] : []),

                            TextInput::make('price')
                                ->label('Giá')
                                ->required()
                                ->suffix('VNĐ')
                                ->extraInputAttributes(['inputmode' => 'numeric']),

                            Select::make('promotions')
                                ->label('Khuyến mãi')
                                ->options($promotionOptions)
                                ->multiple()
                                ->preload()
                                ->searchable(),

                            Toggle::make('over_night')
                                ->label('Qua đêm')
                                ->default(false),

                            Hidden::make('status')->default('available'),
                        ])
                        ->defaultItems(0)
                        ->columns($baselinePriceResolver ? 5 : 4)
                        ->label('')
                        ->emptyLabel('Chưa thiết lập cấu hình giá')
                        ->reorderableWithButtons()
                        ->reorderable(true)
                        ->createItemButtonLabel('Thêm khung giờ')
                        ->columnSpan('full')
                        ->collapsible(),
                ]);
        }

        return $schema;
    }

    /** "Theo Ngày" (styles=2): giá/đêm + cọc + cấu hình phòng. KHÔNG gồm lịch "Ngày đặc biệt" —
     *  đó vẫn là tính năng riêng của trang "Hệ thống giá" (DatePriceCalendarField), bảng giá đặt
     *  tên chỉ thay thế bộ giá nền, không quản lý lịch chi tiết theo ngày. */
    protected static function dayPricingSchema(string $prefix, array $extraInfoFields = []): array
    {
        return [
            Section::make('Thiết lập chung')
                ->schema([
                    ...$extraInfoFields,

                    TextInput::make($prefix . 'price')
                        ->label('Giá mỗi đêm')
                        ->placeholder('VD: 500000')
                        ->suffix('VNĐ')
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                    TimePicker::make($prefix . 'default_checkin')
                        ->label('Giờ nhận phòng mặc định')
                        ->seconds(false)
                        ->native(false)
                        ->locale('vi')
                        ->helperText('Giờ check-in hiển thị trên form đặt theo ngày (VD: 14:00)')
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->default('14:00'),
                    TimePicker::make($prefix . 'default_checkout')
                        ->label('Giờ trả phòng mặc định')
                        ->seconds(false)
                        ->native(false)
                        ->locale('vi')
                        ->helperText('Giờ check-out hiển thị trên form đặt theo ngày (VD: 12:00)')
                        ->timezone('Asia/Ho_Chi_Minh')
                        ->default('12:00'),
                ])->columns(2),

            Section::make('Thiết lập cọc')
                ->description('Cấu hình điều kiện và % tiền cọc khách phải thanh toán khi đặt phòng.')
                ->schema([
                    TextInput::make($prefix . 'deposit_min_nights')
                        ->label('Đặt từ X đêm mới cọc')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(30)
                        ->default(2)
                        ->suffix('đêm')
                        ->helperText('0 = luôn thanh toán 100%. 1 = đặt 1 đêm trở lên có cọc. 2 = từ 2 đêm mới cọc.')
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                    TextInput::make($prefix . 'deposit_multi_night')
                        ->label('Phần trăm cọc (%)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->default(50)
                        ->suffix('%')
                        ->helperText('Ví dụ: 50 → khách cọc 50%, mã cổng gửi sau khi thanh toán phần còn lại.')
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                ])->columns(2),

            Section::make('Cấu hình phòng')
                ->description('Quy định số khách tối đa miễn phí và phụ thu khi có thêm người.')
                ->schema([
                    TextInput::make($prefix . 'room_config_max_free_guests')
                        ->label('Số khách tối đa (miễn phụ thu)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->default(2)
                        ->suffix('người')
                        ->helperText('Ví dụ: 2 → từ khách thứ 3 trở đi mới tính phụ thu.')
                        ->extraInputAttributes(['inputmode' => 'numeric']),
                    TextInput::make($prefix . 'room_config_extra_guest_fee')
                        ->label('Phụ thu mỗi người thêm')
                        ->inputMode('numeric')
                        ->default(50000)
                        ->suffix('VNĐ')
                        ->helperText('Số tiền phụ thu cho mỗi người vượt quá số tối đa miễn phí.')
                        ->dehydrateStateUsing(fn ($state) => (int) preg_replace('/[^0-9]/', '', (string) ($state ?? '0'))),
                ])->columns(2),
        ];
    }
}
