<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Forms;

use Carbon\Carbon;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Group;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use App\Services\PriceBoardSyncService;
use Modules\Book\App\Filament\Traits\HasRoomPricingFormFields;
use Modules\DataPermission\Entities\UserBranchPermission;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;
use Modules\Promotion\App\Models\Promotion;

class PriceBoardForm
{
    use HasRoomPricingFormFields;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                ViewField::make('_schedule_overview')
                    ->label('')
                    ->dehydrated(false)
                    ->live()
                    ->view('book::filament.resources.price-board-resource.schedule-overview')
                    ->viewData(fn (Get $get) => static::scheduleOverviewData($get))
                    ->columnSpanFull(),

                Section::make('Thông tin bảng giá')
                    ->schema([
                        TextInput::make('name')
                            ->label('Tên bảng giá')
                            ->placeholder('VD: Giá Tết 2026, Giá đối tác ABC')
                            ->required()
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Kích hoạt')
                            ->helperText('Bật/tắt có hiệu lực NGAY LẬP TỨC cho các phòng đã chọn, không cần chờ tới ngày.')
                            ->default(true)
                            ->live()
                            ->inline(false),

                        DatePicker::make('start_date')
                            ->label('Ngày bắt đầu')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->live(),

                        DatePicker::make('end_date')
                            ->label('Ngày kết thúc')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->afterOrEqual('start_date')
                            ->live(),

                        Select::make('pricing_mode')
                            ->label('Kiểu bảng giá')
                            ->options([
                                PriceBoard::MODE_OVERRIDE   => 'Thay thế giá cụ thể từng phòng',
                                PriceBoard::MODE_ADJUSTMENT => 'Điều chỉnh % / số tiền trên giá gốc',
                            ])
                            ->default(PriceBoard::MODE_OVERRIDE)
                            ->required()
                            ->live()
                            ->columnSpanFull(),

                        Group::make([
                            Select::make('adjustment_type')
                                ->label('Kiểu điều chỉnh')
                                ->options([
                                    'percentage' => 'Theo % giá gốc',
                                    'fixed'      => 'Số tiền cố định',
                                ])
                                ->default('percentage')
                                ->required(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_ADJUSTMENT)
                                ->live(),

                            TextInput::make('adjustment_value')
                                ->label(fn (Get $get) => $get('adjustment_type') === 'fixed' ? 'Số tiền (+/- VNĐ)' : 'Phần trăm (+/-  %)')
                                ->helperText('Nhập số âm để giảm giá. VD: -15 = giảm 15%, +20 = tăng 20%.')
                                ->numeric()
                                ->required(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_ADJUSTMENT)
                                ->suffix(fn (Get $get) => $get('adjustment_type') === 'fixed' ? 'VNĐ' : '%'),
                        ])
                            ->columns(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_ADJUSTMENT),

                        Textarea::make('note')
                            ->label('Ghi chú')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])->columns(2),

                Section::make('Chọn phòng áp dụng')
                    ->description('Tích chọn phòng cần áp dụng bảng giá (gõ để tìm nhanh theo tên phòng).')
                    ->schema([
                        CheckboxList::make('_room_checklist')
                            ->label('Phòng')
                            ->options(fn () => static::allRoomOptions())
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(3)
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(fn ($state, Get $get, Set $set) => static::syncItemsFromChecklist($state ?? [], $get, $set))
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => $get('pricing_mode') !== PriceBoard::MODE_ADJUSTMENT),

                        CheckboxList::make('product_ids')
                            ->label('Phòng')
                            ->options(fn () => static::allRoomOptions())
                            ->searchable()
                            ->bulkToggleable()
                            ->columns(3)
                            ->required()
                            ->columnSpanFull()
                            ->visible(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_ADJUSTMENT),
                    ])
                    ->columnSpanFull(),

                Section::make('Sửa giá hàng loạt')
                    ->description('Điền ô nào thì áp dụng cho loại phòng đó (để trống = không đổi loại đó) — 1 lần bấm Áp dụng cho TẤT CẢ phòng đang có trong bảng, không cần mở từng phòng.')
                    ->schema([
                        Select::make('_bulk_mode')
                            ->label('Kiểu áp dụng')
                            ->options([
                                'price'   => 'Giá cụ thể (VNĐ)',
                                'percent' => 'Điều chỉnh % trên giá đang có',
                            ])
                            ->default('price')
                            ->live()
                            ->dehydrated(false)
                            ->columnSpanFull(),

                        Section::make('Theo Ngày')
                            ->icon('heroicon-o-calendar-days')
                            ->compact()
                            ->schema([
                                TextInput::make('_bulk_day_value')
                                    ->label(fn (Get $get) => $get('_bulk_mode') === 'percent' ? 'Mức % (+/-)' : 'Giá/đêm mới (VNĐ)')
                                    ->helperText('Áp cho tất cả phòng Theo Ngày đang có trong bảng. Để trống nếu không đổi.')
                                    ->numeric()
                                    ->dehydrated(false)
                                    ->columnSpan(1),
                            ]),

                        Section::make('Theo Khung Giờ')
                            ->icon('heroicon-o-clock')
                            ->compact()
                            ->schema([
                                Repeater::make('_bulk_slot_rules')
                                    ->label('Các khung giờ cần đổi giá')
                                    ->helperText('Thêm nhiều dòng để đổi giá nhiều khung giờ cùng lúc, VD: khung 1 → 200.000, khung 2 → 300.000... — 1 lần bấm "Áp dụng" bên dưới là áp hết cho mọi phòng trong bảng.')
                                    ->schema([
                                        Select::make('pick_mode')
                                            ->label('Chọn khung giờ theo')
                                            ->options([
                                                'position' => 'Vị trí (đầu/cuối/thứ N)',
                                                'specific' => 'Khung giờ cụ thể',
                                            ])
                                            ->default('position')
                                            ->live()
                                            ->dehydrated(false)
                                            ->columnSpan(1),

                                        Select::make('timeslot_id')
                                            ->label('Khung giờ')
                                            ->options(fn () => TimeSlot::where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))->pluck('label', 'id'))
                                            ->searchable()
                                            ->dehydrated(false)
                                            ->columnSpan(1)
                                            ->visible(fn (Get $get) => $get('pick_mode') === 'specific'),

                                        Select::make('position')
                                            ->label('Vị trí khung giờ')
                                            ->options([
                                                'first' => 'Khung đầu tiên',
                                                'last'  => 'Khung cuối cùng',
                                                'nth'   => 'Khung thứ N',
                                            ])
                                            ->default('first')
                                            ->live()
                                            ->dehydrated(false)
                                            ->columnSpan(1)
                                            ->visible(fn (Get $get) => $get('pick_mode') === 'position'),

                                        TextInput::make('position_n')
                                            ->label('N = ?')
                                            ->numeric()
                                            ->minValue(1)
                                            ->dehydrated(false)
                                            ->columnSpan(1)
                                            ->visible(fn (Get $get) => $get('pick_mode') === 'position' && $get('position') === 'nth'),

                                        TextInput::make('value')
                                            ->label(fn (Get $get) => $get('../../_bulk_mode') === 'percent' ? 'Mức % (+/-)' : 'Giá mới (VNĐ)')
                                            ->numeric()
                                            ->dehydrated(false)
                                            ->required()
                                            ->columnSpan(1),
                                    ])
                                    // Luôn 1 cột — lưới chia cột theo breakpoint MÀN HÌNH chứ không
                                    // theo bề rộng thật của khối cha (section này chỉ chiếm nửa trang
                                    // do nằm cạnh "Theo Ngày"), chia nhiều cột dễ bị bóp chật/cắt chữ dù
                                    // màn hình máy tính to. Mỗi field 1 dòng riêng cho chắc chắn không
                                    // lệch, kể cả trên mobile.
                                    ->columns(1)
                                    ->defaultItems(0)
                                    ->addActionLabel('Thêm khung giờ cần đổi')
                                    ->dehydrated(false)
                                    ->columnSpanFull(),
                            ])
                            ->description('Áp cho tất cả phòng Theo Khung Giờ đang có trong bảng, tại đúng (các) khung giờ chọn bên dưới.'),

                        Actions::make([
                            Action::make('apply_bulk_price')
                                ->label('Áp dụng cho mọi phòng đang có trong bảng')
                                ->icon('heroicon-o-bolt')
                                ->color('warning')
                                ->action(function (Get $get, Set $set, ?PriceBoard $record) {
                                    $mode      = $get('_bulk_mode');
                                    $dayValue  = $get('_bulk_day_value');
                                    $slotRules = collect($get('_bulk_slot_rules') ?? [])
                                        ->filter(fn ($rule) => ($rule['value'] ?? null) !== null && $rule['value'] !== '')
                                        ->values();

                                    if (($dayValue === null || $dayValue === '') && $slotRules->isEmpty()) {
                                        return;
                                    }

                                    $items = $get('items') ?? [];

                                    if ($dayValue !== null && $dayValue !== '') {
                                        $items = static::applyBulkDayPrice($items, $mode, (float) $dayValue);
                                    }

                                    // Áp TỪNG dòng 1 theo đúng thứ tự đã thêm — mỗi dòng chỉ đổi 1 vị
                                    // trí/khung giờ cụ thể nên không ghi đè lẫn nhau (khung 1→A xong mới
                                    // tới khung 2→B, không phải cả 2 cùng ghi vào 1 vị trí).
                                    foreach ($slotRules as $rule) {
                                        $items = ($rule['pick_mode'] ?? 'position') === 'position'
                                            ? static::applyBulkByPosition($items, $rule['position'] ?? 'first', (int) ($rule['position_n'] ?? 1), $mode, (float) $rule['value'])
                                            : static::applyBulkByTimeslot($items, $rule['timeslot_id'] ?? null, $mode, (float) $rule['value']);
                                    }

                                    $set('items', $items);

                                    static::persistBulkItems($record, $items, $set);
                                }),
                        ])->columnSpanFull(),
                    ])
                    // Luôn xếp dọc (1 cột) — "Theo Khung Giờ" giờ có thể nhiều dòng, để cạnh "Theo
                    // Ngày" sẽ bị ép chỉ còn nửa bề rộng trang, dễ bóp chật các field bên trong.
                    ->columns(1)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('pricing_mode') !== PriceBoard::MODE_ADJUSTMENT),

                Repeater::make('items')
                    ->label('Chi tiết giá từng phòng đã chọn')
                    ->helperText('Đã điền sẵn giá/khung giờ hiện tại của từng phòng — mở ra chỉ để sửa số cần đổi.')
                    ->schema([
                        Hidden::make('product_id'),

                        Group::make(
                            static::slotPricingSchema('', true, fn () => static::promotionOptions(), [], static::baselinePriceResolver())
                        )->visible(fn (Get $get) => static::styleOf($get('product_id')) === 1),

                        Group::make(
                            static::compactDaySchema()
                        )->visible(fn (Get $get) => static::styleOf($get('product_id')) === 2),
                    ])
                    ->itemLabel(fn (array $state) => Product::find($state['product_id'] ?? null)?->name ?? 'Phòng')
                    ->collapsed()
                    ->collapsible()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => $get('pricing_mode') !== PriceBoard::MODE_ADJUSTMENT),
            ]);
    }

    /** Dữ liệu 1 dòng phòng trong Repeater 'items', điền sẵn từ giá/cấu hình HIỆN TẠI của phòng
     *  (products/room_time_slots) — để admin chỉ cần sửa số cần đổi thay vì phải thêm lại từ đầu
     *  từng khung giờ. Dùng khi chọn phòng (afterStateUpdated) lẫn khi "Chọn nhanh theo chi nhánh". */
    private static function defaultRowFor(Product $product): array
    {
        $row = [
            'product_id'                  => $product->id,
            'full_booking_discount'       => $product->full_booking_discount,
            'room_config_max_free_guests' => (int) ($product->room_config['max_free_guests'] ?? 2),
            'room_config_extra_guest_fee' => (int) ($product->room_config['extra_guest_fee'] ?? 0),
            'bulk_discount_rules'         => $product->bulk_discount_rules ?? [],
        ];

        if ((int) ($product->styles ?? 1) === 2) {
            $row['price']               = $product->price;
            $row['default_checkin']     = $product->default_checkin ?? '14:00';
            $row['default_checkout']    = $product->default_checkout ?? '12:00';
            $row['deposit_min_nights']  = $product->deposit_min_nights ?? 2;
            $row['deposit_multi_night'] = $product->deposit_multi_night ?? 50;

            return $row;
        }

        $baseline = static::baselinePriceResolver();

        $row['roomTimeSlots'] = $product->roomTimeSlots()
            ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
            ->with('timeSlot')
            ->get()
            // "Khung đầu tiên/cuối cùng" (sửa giá hàng loạt theo vị trí) chỉ có ý nghĩa nếu thứ tự
            // ở đây ổn định theo giờ bắt đầu tăng dần — không phụ thuộc thứ tự lưu gốc trong DB.
            ->sortBy(fn ($slot) => $slot->timeSlot?->start_time ?? '99:99:99')
            ->values()
            ->map(fn ($slot) => [
                'timeslot_id'     => $slot->timeslot_id,
                'price'           => number_format((int) $slot->price, 0, ',', '.'),
                // Nhúng sẵn "Giá gốc" ngay khi dựng dữ liệu dòng — TableRepeater (thư viện ngoài)
                // không hỗ trợ afterStateHydrated() đọc field ở Repeater cha (product_id) một cách
                // đáng tin cậy lúc tải trang, nên tính sẵn ở đây thay vì để field tự tính lúc hydrate.
                '_baseline_price' => $baseline($product->id, $slot->timeslot_id),
                'promotions'      => [],
                'over_night'      => $slot->over_night,
                'status'          => $slot->status ?? 'available',
            ])
            ->toArray();

        return $row;
    }

    /** Form RÚT GỌN cho phòng "Theo Ngày" trong bảng giá — chỉ nổi bật đúng 1 ô "Giá mỗi đêm" để đổi
     *  nhanh (đúng yêu cầu: đổi giá phòng theo ngày cũng đơn giản như đổi giá theo khung giờ, không
     *  cần thấy hết giờ nhận/trả phòng, cọc, khách... trừ khi thật sự cần sửa thêm). Khác với
     *  dayPricingSchema() dùng ở SettingBook (trang cấu hình gốc, cần đầy đủ mọi field luôn hiện). */
    private static function compactDaySchema(): array
    {
        return [
            TextInput::make('price')
                ->label('Giá mỗi đêm')
                ->placeholder('VD: 500000')
                ->numeric()
                ->suffix('VNĐ')
                ->extraInputAttributes(['inputmode' => 'numeric']),

            Section::make('Cài đặt nâng cao (giờ nhận/trả phòng, cọc, khách)')
                ->description('Chỉ mở nếu cần đổi thêm — để trống thì giữ nguyên như hiện tại.')
                ->schema([
                    TimePicker::make('default_checkin')
                        ->label('Giờ nhận phòng mặc định')
                        ->seconds(false)
                        ->native(false)
                        ->locale('vi')
                        ->timezone('Asia/Ho_Chi_Minh'),

                    TimePicker::make('default_checkout')
                        ->label('Giờ trả phòng mặc định')
                        ->seconds(false)
                        ->native(false)
                        ->locale('vi')
                        ->timezone('Asia/Ho_Chi_Minh'),

                    TextInput::make('deposit_min_nights')
                        ->label('Đặt từ X đêm mới cọc')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(30)
                        ->suffix('đêm')
                        ->extraInputAttributes(['inputmode' => 'numeric']),

                    TextInput::make('deposit_multi_night')
                        ->label('Phần trăm cọc (%)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(100)
                        ->suffix('%')
                        ->extraInputAttributes(['inputmode' => 'numeric']),

                    TextInput::make('room_config_max_free_guests')
                        ->label('Số khách tối đa (miễn phụ thu)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10)
                        ->suffix('người')
                        ->extraInputAttributes(['inputmode' => 'numeric']),

                    TextInput::make('room_config_extra_guest_fee')
                        ->label('Phụ thu mỗi người thêm')
                        ->inputMode('numeric')
                        ->suffix('VNĐ')
                        ->dehydrateStateUsing(fn ($state) => (int) preg_replace('/[^0-9]/', '', (string) ($state ?? '0'))),
                ])
                ->columns(2)
                ->collapsed()
                ->collapsible()
                ->columnSpanFull(),
        ];
    }

    /** Ghi $items xuống DB NGAY khi $record đã tồn tại (đang sửa 1 bảng có sẵn) — không đợi bấm
     *  "Lưu thay đổi". Bắt buộc phải làm vậy vì TableRepeater (khung giờ) là widget bên thứ 3, khi
     *  bị $set() giá trị mới từ 1 Action bên ngoài nó không tự vẽ lại input — nếu chỉ set vào state
     *  rồi chờ người dùng bấm Lưu, trình duyệt vẫn gửi lên giá trị CŨ đang hiển thị trên màn hình,
     *  khiến "Áp dụng" nhìn như chạy nhưng lưu xong giá không đổi. Ghi thẳng DB rồi nạp lại 'items'
     *  từ chính dữ liệu vừa lưu để đảm bảo đúng, bất kể widget có vẽ lại trên UI hay không.
     *
     *  $record = null khi đang ở trang Tạo mới (bảng chưa tồn tại, chưa có gì để ghi xuống) — lúc đó
     *  đành chấp nhận chỉ set vào state, nhắc admin lưu bảng trước rồi mới dùng nút áp dụng hàng loạt. */
    private static function persistBulkItems(?PriceBoard $record, array $items, Set $set): void
    {
        if (! $record || ! $record->exists) {
            Notification::make()
                ->title('Đã áp lên form — hãy bấm "Lưu thay đổi" để tạo bảng trước, sau đó dùng lại nút áp dụng hàng loạt để chắc chắn lưu đúng.')
                ->warning()
                ->send();

            return;
        }

        $service = app(PriceBoardSyncService::class);
        $service->saveOverrideItems($record, $items);
        $service->resyncBoardProducts($record);

        $set('items', static::buildItemsFromBoard($record));

        Notification::make()
            ->title('Đã lưu và áp dụng ngay xuống hệ thống')
            ->success()
            ->send();
    }

    /** Dựng lại state 'items' cho Repeater từ đúng dữ liệu ĐANG LƯU trong DB của $board — dùng khi
     *  mở trang Sửa (EditPriceBoard::mutateFormDataBeforeFill) VÀ sau mỗi lần persistBulkItems() để
     *  đảm bảo form luôn khớp với DB. */
    public static function buildItemsFromBoard(PriceBoard $board): array
    {
        $baseline = static::baselinePriceResolver();

        return $board->items()->with(['timeSlots.timeslot', 'product'])->get()
            ->map(function ($item) use ($baseline) {
                $style = (int) ($item->product->styles ?? 1);

                $row = [
                    'product_id'                  => $item->product_id,
                    'full_booking_discount'       => $item->full_booking_discount,
                    'bulk_discount_rules'         => $item->bulk_discount_rules ?? [],
                    'room_config_max_free_guests' => (int) ($item->room_config['max_free_guests'] ?? 2),
                    'room_config_extra_guest_fee' => (int) ($item->room_config['extra_guest_fee'] ?? 0),
                ];

                if ($style === 2) {
                    $row['price']               = $item->price;
                    $row['default_checkin']     = $item->default_checkin;
                    $row['default_checkout']    = $item->default_checkout;
                    $row['deposit_min_nights']  = $item->deposit_min_nights;
                    $row['deposit_multi_night'] = $item->deposit_multi_night;

                    return $row;
                }

                $row['roomTimeSlots'] = $item->timeSlots
                    ->sortBy(fn ($slot) => $slot->timeslot?->start_time ?? '99:99:99')
                    ->values()
                    ->map(fn ($slot) => [
                        'timeslot_id'     => $slot->timeslot_id,
                        'price'           => number_format((int) $slot->price, 0, ',', '.'),
                        '_baseline_price' => $baseline($item->product_id, $slot->timeslot_id),
                        'over_night'      => $slot->over_night,
                        'status'          => $slot->status,
                    ])
                    ->toArray();

                return $row;
            })
            ->toArray();
    }

    /** Closure tra "Giá gốc" — giá đang lưu ở "Bảng giá mặc định" (price_board_items/
     *  price_board_time_slots của bảng is_default=true) cho đúng (phòng, khung giờ) — KHÔNG phải giá
     *  đang chạy thật trên products/room_time_slots, vì phòng đó có thể đang bị 1 bảng đặt tên KHÁC
     *  áp đè tạm thời (xem giải thích ở PriceBoardSyncService::applyForProduct()). Cache theo product
     *  trong 1 request — TableRepeater gọi lại cho từng dòng/mỗi lần đổi khung giờ, tránh query lặp
     *  lại y hệt nhiều lần trên cùng 1 phòng.
     */
    private static function baselinePriceResolver(): \Closure
    {
        $cache = [];

        return function (int|string|null $productId, int|string|null $timeslotId) use (&$cache): ?int {
            if (! $productId || ! $timeslotId) {
                return null;
            }

            if (! array_key_exists($productId, $cache)) {
                $defaultBoardId = app(PriceBoardSyncService::class)->defaultBoard()->id;

                $cache[$productId] = \Modules\Product\App\Models\PriceBoardItem::where('price_board_id', $defaultBoardId)
                    ->where('product_id', $productId)
                    ->first()
                    ?->timeSlots
                    ->pluck('price', 'timeslot_id')
                    ->all() ?? [];
            }

            return isset($cache[$productId][$timeslotId]) ? (int) $cache[$productId][$timeslotId] : null;
        };
    }

    /** Dữ liệu cho khối "Tổng quan lịch trình" ở đầu form — tính trực tiếp trên GIÁ TRỊ ĐANG NHẬP
     *  trên form (không đọc lại từ $record), để admin đổi ngày là thấy cập nhật ngay trước khi bấm
     *  Lưu. Không giới hạn ngày bắt đầu/kết thúc (để trống = vô thời hạn) vẫn hiển thị hợp lý. */
    private static function scheduleOverviewData(Get $get): array
    {
        $isActive  = (bool) ($get('is_active') ?? true);
        $startDate = $get('start_date') ? Carbon::parse($get('start_date'))->startOfDay() : null;
        $endDate   = $get('end_date') ? Carbon::parse($get('end_date'))->endOfDay() : null;
        $today     = now()->startOfDay();

        $status = match (true) {
            ! $isActive                                     => ['Đã tắt', 'gray'],
            $endDate && $today->gt($endDate)                 => ['Hết hạn', 'danger'],
            $startDate && $today->lt($startDate)             => ['Chờ áp dụng', 'warning'],
            default                                          => ['Đang áp dụng', 'success'],
        };

        $totalSeconds   = ($startDate && $endDate) ? max(1, $startDate->diffInSeconds($endDate)) : null;
        $elapsedSeconds = ($startDate && $endDate) ? min($totalSeconds, max(0, $startDate->diffInSeconds(now(), false))) : null;
        $progress       = $totalSeconds ? (int) round(($elapsedSeconds / $totalSeconds) * 100) : ($isActive ? 100 : 0);

        return [
            'statusLabel'   => $status[0],
            'statusColor'   => $status[1],
            'progressPercent' => max(0, min(100, $progress)),
            'durationLabel' => static::formatDuration($startDate, $endDate),
            'startMonthLabel'    => $startDate ? 'T' . $startDate->format('m') : '—',
            'startDayLabel'      => $startDate ? $startDate->format('d') : '—',
            'startTimeLabel'     => $startDate ? $startDate->format('H:i') : 'Không giới hạn',
            'startDateFullLabel' => $startDate ? $startDate->translatedFormat('l, Y') : '',
            'endMonthLabel'      => $endDate ? 'T' . $endDate->format('m') : '—',
            'endDayLabel'        => $endDate ? $endDate->format('d') : '—',
            'endTimeLabel'       => $endDate ? $endDate->format('H:i') : 'Không giới hạn',
            'endDateFullLabel'   => $endDate ? $endDate->translatedFormat('l, Y') : '',
        ];
    }

    private static function formatDuration(?Carbon $start, ?Carbon $end): string
    {
        if (! $start || ! $end) {
            return 'Không giới hạn thời gian';
        }

        $days  = $start->diffInDays($end);
        $hours = $start->copy()->addDays($days)->diffInHours($end);

        if ($days === 0) {
            return $hours . ' giờ';
        }

        return $hours > 0 ? "{$days} ngày {$hours} giờ" : "{$days} ngày";
    }

    private static function styleOf(?string $productId): int
    {
        if (! $productId) {
            return 1;
        }

        return (int) (Product::find($productId)?->styles ?? 1);
    }

    /** Toàn bộ phòng đang hoạt động — dùng làm options() cho 2 CheckboxList chọn phòng. Trang "Hệ
     *  thống giá" (SettingBook) đã có bộ lọc chi nhánh riêng ở cấp trang, nên ở đây không lọc lại
     *  theo chi nhánh/đối tác/loại phòng nữa — chỉ cần danh sách phòng để tích chọn (đã có tìm kiếm
     *  theo tên qua ->searchable() trên CheckboxList). */
    private static function allRoomOptions(): array
    {
        return Product::where('is_activated', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /** Đồng bộ Repeater 'items' theo đúng tập phòng đang tích trong CheckboxList — thêm dòng mới
     *  (điền sẵn giá hiện tại) cho phòng vừa tích, gỡ dòng của phòng vừa bỏ tích. Dòng đã có sẵn của
     *  phòng vẫn đang tích giữ nguyên, không bị ghi đè lại (không mất dữ liệu đã tuỳ chỉnh). */
    private static function syncItemsFromChecklist(array $checkedIds, Get $get, Set $set): void
    {
        $checkedIds  = array_map('strval', $checkedIds);
        $items       = $get('items') ?? [];
        $existingIds = collect($items)->pluck('product_id')->filter()->map(fn ($v) => (string) $v)->all();

        foreach ($checkedIds as $id) {
            if (in_array($id, $existingIds, true)) {
                continue;
            }

            $product = Product::find($id);
            if ($product) {
                $items[] = static::defaultRowFor($product);
            }
        }

        $items = collect($items)
            ->filter(fn ($item) => in_array((string) ($item['product_id'] ?? ''), $checkedIds, true))
            ->values()
            ->all();

        $set('items', $items);
    }

    /** Sửa giá 1 khung giờ CÙNG ID ở mọi phòng trong $items — dùng khi các phòng chia sẻ chung 1
     *  khung giờ tái sử dụng (VD: mọi phòng đều có "17:00-19:00"). */
    private static function applyBulkByTimeslot(array $items, ?string $timeslotId, string $mode, float $value): array
    {
        if (! $timeslotId) {
            return $items;
        }

        foreach ($items as $i => $item) {
            foreach (($item['roomTimeSlots'] ?? []) as $j => $slot) {
                if ((string) ($slot['timeslot_id'] ?? '') !== (string) $timeslotId) {
                    continue;
                }

                $items[$i]['roomTimeSlots'][$j]['price'] = static::recalcPrice($slot['price'] ?? '0', $mode, $value);
            }
        }

        return $items;
    }

    /** Sửa giá khung giờ theo VỊ TRÍ (khung đầu tiên/cuối/thứ N) của TỪNG phòng — dùng khi mỗi phòng
     *  có khung giờ khác nhau (VD: phòng 1 là 7h-9h, phòng 2 là 8h-9h) nhưng đều là "khung đầu tiên"
     *  của phòng đó. Vị trí tính theo thứ tự giờ bắt đầu tăng dần (xem defaultRowFor()). */
    private static function applyBulkByPosition(array $items, string $position, int $n, string $mode, float $value): array
    {
        foreach ($items as $i => $item) {
            $slots = $item['roomTimeSlots'] ?? [];
            if (empty($slots)) {
                continue;
            }

            $index = match ($position) {
                'last'  => count($slots) - 1,
                'nth'   => $n - 1,
                default => 0, // 'first'
            };

            if (! isset($slots[$index])) {
                continue;
            }

            $items[$i]['roomTimeSlots'][$index]['price'] = static::recalcPrice($slots[$index]['price'] ?? '0', $mode, $value);
        }

        return $items;
    }

    /** Sửa giá mỗi đêm (field 'price' top-level) cho TẤT CẢ phòng Theo Ngày trong $items — bỏ qua
     *  phòng Theo Khung Giờ. Nhận biết bằng styleOf($item['product_id']) — KHÔNG dùng
     *  array_key_exists('roomTimeSlots', $item) vì Filament tự khởi tạo key này = [] cho mọi item
     *  kể cả khi Group chứa TableRepeater đó đang ẩn (xem ghi chú ở visible() của 2 section bulk-edit
     *  phía trên), nên key luôn "tồn tại" dù rỗng — không phân biệt được loại phòng qua đó. Field
     *  'price' của phòng Theo Ngày lưu số thô (không format dấu chấm như roomTimeSlots.price), nên
     *  tính trực tiếp bằng (float) thay vì str_replace như recalcPrice(). */
    private static function applyBulkDayPrice(array $items, string $mode, float $value): array
    {
        foreach ($items as $i => $item) {
            if (static::styleOf($item['product_id'] ?? null) !== 2) {
                continue;
            }

            $current = (float) ($item['price'] ?? 0);

            $items[$i]['price'] = $mode === 'percent'
                ? (int) round($current * (1 + $value / 100))
                : (int) round($value);
        }

        return $items;
    }

    private static function recalcPrice(mixed $currentFormatted, string $mode, float $value): string
    {
        $current = (int) str_replace(['.', ','], '', (string) $currentFormatted);
        $new     = $mode === 'percent'
            ? (int) round($current * (1 + $value / 100))
            : (int) round($value);

        return number_format($new, 0, ',', '.');
    }

    private static function promotionOptions(): array
    {
        $user  = auth()->user();
        $query = Promotion::where('is_active', true);

        if (! $user || $user->isSuperAdmin()) {
            return $query->pluck('name', 'id')->toArray();
        }

        $branchIds          = UserBranchPermission::where('user_id', $user->id)->pluck('category_id');
        $overlappingUserIds = UserBranchPermission::whereIn('category_id', $branchIds)->pluck('user_id');

        return $query->where(function ($q) use ($overlappingUserIds) {
            $q->whereNull('created_by')->orWhereIn('created_by', $overlappingUserIds);
        })->pluck('name', 'id')->toArray();
    }
}
