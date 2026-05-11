<?php

namespace Modules\Book\App\Filament\Resources\BookResource\Pages;

use Modules\Book\App\Filament\Resources\BookResource;
use Filament\Resources\Pages\Page;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\DatePicker;
use Modules\Book\App\Filament\Forms\Components\DatePriceCalendarField;
use Awcodes\TableRepeater\Components\TableRepeater;
use Awcodes\TableRepeater\Header;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Modules\Promotion\App\Models\Promotion;
use Filament\Notifications\Notification;
use Modules\Book\App\Filament\Traits\HasBookingHeaderActions;
use Modules\Promotion\App\Models\Coupon;

class SettingBook extends Page implements HasForms
{
    use InteractsWithForms;
    use HasBookingHeaderActions;

    protected static string $resource = BookResource::class;
    protected static ?string $slug = 'cai-dat-he-thong-booking';
    protected static ?string $title = 'Hệ thống Giá & Ưu đãi';

    protected static string $view = 'book::filament.resources.book-resource.pages.setting-book';

    public ?array $data = [];

    /** Returns product IDs the current user is allowed to manage. null = all. */
    private function allowedProductIds(): ?array
    {
        $user = auth()->user();
        if (! $user || $user->isSuperAdmin()) {
            return null;
        }

        $categoryIds = $user->allowedCategoryIds();
        if (empty($categoryIds)) {
            return [];
        }

        return \Modules\Category\Entities\Categorizable::where('categorizable_type', Product::class)
            ->whereIn('category_id', $categoryIds)
            ->distinct()
            ->pluck('categorizable_id')
            ->map(fn ($id) => (string) $id)
            ->toArray();
    }

    private function scopedProductQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = Product::query()->where('is_activated', true);
        $ids   = $this->allowedProductIds();

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn('id', $ids);
    }

    public function mount(): void
    {
        $rooms = $this->scopedProductQuery()
            ->with('roomTimeSlots.timeslot', 'roomTimeSlots.promotions', 'roomTimeSlots.coupons')
            ->get();

        $formData = [];
        foreach ($rooms as $product) {
            $productStyle = (int) ($product->styles ?? 1);

            $roomData = [
                'product_id'            => $product->id,
                'product_name'          => $product->name,
                'price'                 => (float) $product->price,
                'full_booking_discount' => $product->full_booking_discount,
                'is_in_stock'           => (bool) $product->is_in_stock,
                'default_checkin'       => $product->default_checkin ?? '14:00',
                'default_checkout'      => $product->default_checkout ?? '12:00',
                'deposit_1_night'       => $product->deposit_1_night ?? 100,
                'deposit_multi_night'   => $product->deposit_multi_night ?? 50,
                'deposit_min_nights'    => $product->deposit_min_nights ?? 2,
                'room_config_max_free_guests' => (int)(($product->room_config['max_free_guests'] ?? 2)),
                'room_config_extra_guest_fee' => (int)(($product->room_config['extra_guest_fee'] ?? 50000)),
            ];

            if ($productStyle === 1) {
                $roomData['roomTimeSlots'] = $product->roomTimeSlots->map(function ($slot) {
                    return [
                        'id'          => $slot->id,
                        'timeslot_id' => $slot->timeslot_id,
                        'price'       => number_format((int) $slot->price, 0, ',', '.'),
                        'promotions'  => $slot->promotions->pluck('id')->toArray(),
                        'over_night'  => $slot->over_night,
                        'status'      => $slot->status,
                    ];
                })->toArray();
            } else {
                $roomData['dateTimeSlots'] = $product->roomTimeSlots
                    ->filter(fn($s) => $s->timeslot && $s->timeslot->type === 'date')
                    ->map(fn($slot) => [
                        'id'         => $slot->id,
                        'date'       => $slot->timeslot->label,
                        // null = giá chưa ghi đè (chỉ có khuyến mãi)
                        'price'      => $slot->price !== null ? number_format((int) $slot->price, 0, ',', '.') : null,
                        'promotions' => $slot->promotions->whereNotIn('type', ['increase_percentage', 'increase_fixed'])->pluck('id')->toArray(),
                        'surcharges' => $slot->promotions->whereIn('type', ['increase_percentage', 'increase_fixed'])->pluck('id')->toArray(),
                        'coupons'    => $slot->coupons->pluck('id')->toArray(),
                        'checkin'    => $slot->checkin,
                        'checkout'   => $slot->checkout,
                    ])
                    ->values()
                    ->toArray();
            }

            $formData['room_' . $product->id] = $roomData;
        }

        $this->form->fill($formData);
    }

    public function form(Form $form): Form
    {
        $rooms = $this->scopedProductQuery()->get();

        $buildSlotRoomTab = function ($product) {
            return Tabs\Tab::make($product->name)
                ->icon('heroicon-o-home')
                ->schema([
                    Section::make('Thông tin Phòng')
                        ->schema([
                            Hidden::make('room_' . $product->id . '.product_id')
                                ->default($product->id),
                            TextInput::make('room_' . $product->id . '.product_name')
                                ->label('Tên Phòng')
                                ->default($product->name)
                                ->disabled()
                                ->dehydrated(false),

                            TextInput::make('room_' . $product->id . '.full_booking_discount')
                                ->label('Giảm giá khi đặt Full phòng')
                                ->placeholder('VD: 10% hoặc 50000')
                                ->helperText('Nhập % (VD: 10%) hoặc số tiền cố định (VD: 50000)')
                                ->maxLength(50),
                        ])->columns(2),

                    Section::make('Khung giờ, giá, Thiết lập khuyến mãi')
                        ->description('Thiết lập khung giờ, giá và khuyến mãi cho phòng ' . $product->name)
                        ->schema([
                            TableRepeater::make('room_' . $product->id . '.roomTimeSlots')
                                ->headers([
                                    Header::make('timeslot_id')->label('Khung giờ')->width('200px'),
                                    Header::make('price')->label('Giá')->width('150px'),
                                    Header::make('promotions')->label('Khuyến mãi')->width('250px'),
                                    Header::make('over_night')->label('Qua đêm')->width('100px'),
                                    Header::make('status')->label('Trạng thái')->width('100px'),
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
                                        ->required(),

                                    TextInput::make('price')
                                        ->label('Giá')
                                        ->required()
                                        ->suffix('VNĐ')
                                        ->extraInputAttributes(['inputmode' => 'numeric']),

                                    Select::make('promotions')
                                        ->label('Khuyến mãi')
                                        ->options(Promotion::where('is_active', true)->pluck('name', 'id'))
                                        ->multiple()
                                        ->preload()
                                        ->searchable(),

                                    Toggle::make('over_night')
                                        ->label('Qua đêm')
                                        ->default(false),

                                    Select::make('status')
                                        ->label('Trạng thái')
                                        ->options([
                                            'available' => 'Còn trống',
                                            'booked' => 'Đã đặt',
                                            'selected' => 'Đang chọn',
                                        ])
                                        ->default('available')
                                        ->required(),
                                ])
                                ->defaultItems(0)
                                ->columns(5)
                                ->label('')
                                ->reorderableWithButtons()
                                ->reorderable(true)
                                ->createItemButtonLabel('Thêm khung giờ')
                                ->columnSpan('full')
                                ->collapsible()
                        ]),
                ]);
        };

        $buildDayRoomTab = function ($product) {
            return Tabs\Tab::make($product->name)
                ->icon('heroicon-o-home')
                ->schema([
                    Section::make('Thiết lập chung')
                        ->schema([
                            Hidden::make('room_' . $product->id . '.product_id')
                                ->default($product->id),
                            TextInput::make('room_' . $product->id . '.product_name')
                                ->label('Tên Phòng')
                                ->default($product->name)
                                ->disabled()
                                ->dehydrated(false),
                            TextInput::make('room_' . $product->id . '.price')
                                ->label('Giá mỗi đêm')
                                ->placeholder('VD: 500000')
                                ->suffix('VNĐ')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TimePicker::make('room_' . $product->id . '.default_checkin')
                                ->label('Giờ nhận phòng mặc định')
                                ->seconds(false)
                                ->native(false)
                                ->locale('vi')
                                ->helperText('Giờ check-in hiển thị trên form đặt theo ngày (VD: 14:00)')
                                ->timezone('Asia/Ho_Chi_Minh')
                                ->default('14:00'),
                            TimePicker::make('room_' . $product->id . '.default_checkout')
                                ->label('Giờ trả phòng mặc định')
                                ->seconds(false)
                                ->native(false)
                                ->locale('vi')
                                ->helperText('Giờ check-out hiển thị trên form đặt theo ngày (VD: 12:00)')
                                ->timezone('Asia/Ho_Chi_Minh')  
                                ->seconds(false)
                                ->default('12:00'),
                        ])->columns(2),

                    Section::make('Thiết lập cọc')
                        ->description('Cấu hình điều kiện và % tiền cọc khách phải thanh toán khi đặt phòng.')
                        ->schema([
                            TextInput::make('room_' . $product->id . '.deposit_min_nights')
                                ->label('Đặt từ X đêm mới cọc')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(30)
                                ->default(2)
                                ->suffix('đêm')
                                ->helperText('0 = luôn thanh toán 100%. 1 = đặt 1 đêm trở lên có cọc. 2 = từ 2 đêm mới cọc.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TextInput::make('room_' . $product->id . '.deposit_multi_night')
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
                            TextInput::make('room_' . $product->id . '.room_config_max_free_guests')
                                ->label('Số khách tối đa (miễn phụ thu)')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(10)
                                ->default(2)
                                ->suffix('người')
                                ->helperText('Ví dụ: 2 → từ khách thứ 3 trở đi mới tính phụ thu.')
                                ->extraInputAttributes(['inputmode' => 'numeric']),
                            TextInput::make('room_' . $product->id . '.room_config_extra_guest_fee')
                                ->label('Phụ thu mỗi người thêm')
                                ->inputMode('numeric')
                                ->default(50000)
                                ->suffix('VNĐ')
                                ->helperText('Số tiền phụ thu cho mỗi người vượt quá số tối đa miễn phí.')
                                ->dehydrateStateUsing(fn ($state) => (int) preg_replace('/[^0-9]/', '', (string)($state ?? '0'))),
                        ])->columns(2),

                    Section::make('Ngày đặc biệt & Khuyến mãi')
                        ->description('Nhấn vào ô ngày để thiết lập giá và khuyến mãi. Ưu tiên cao hơn giá gốc của phòng.')
                        ->schema([
                            DatePriceCalendarField::make('room_' . $product->id . '.dateTimeSlots')
                                ->roomId($product->id)
                                ->basePrice((int) ($product->price ?? 0))
                                ->label('')
                                ->columnSpan('full'),
                        ]),
                ]);
        };

        $slotRooms = $rooms->filter(fn($p) => ((int)($p->styles ?? 1)) === 1);
        $dayRooms  = $rooms->filter(fn($p) => ((int)($p->styles ?? 1)) === 2);

        $slotTabs = $slotRooms->map(fn ($p) => $buildSlotRoomTab($p))->values()->all();
        $dayTabs  = $dayRooms->map(fn ($p) => $buildDayRoomTab($p))->values()->all();

        return $form
            ->schema([
                Tabs::make('StyleTabs')
                    ->tabs([
                        Tabs\Tab::make('Theo Khung Giờ')
                            ->icon('heroicon-o-clock')
                            ->badge($slotRooms->count())
                            ->schema(
                                $slotTabs
                                    ? [Tabs::make('SlotRooms')->tabs($slotTabs)->columnSpanFull()]
                                    : [\Filament\Forms\Components\Placeholder::make('no_slot')->label('')->content('Chưa có phòng nào theo kiểu Khung Giờ.')]
                            ),
                        Tabs\Tab::make('Theo Ngày')
                            ->icon('heroicon-o-calendar-days')
                            ->badge($dayRooms->count())
                            ->schema(
                                $dayTabs
                                    ? [Tabs::make('DayRooms')->tabs($dayTabs)->columnSpanFull()]
                                    : [\Filament\Forms\Components\Placeholder::make('no_day')->label('')->content('Chưa có phòng nào theo kiểu Theo Ngày.')]
                            ),
                    ])
                    ->columnSpanFull(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        try {
            $data = $this->data ?? [];
            $updated = false;
            $allowedIds = $this->allowedProductIds(); // null = super_admin

            if (empty($data)) {
                Notification::make()->title('Lỗi: $this->data rỗng, form chưa được fill đúng')->danger()->send();
                return;
            }

            foreach ($data as $key => $roomData) {
                if (str_starts_with($key, 'room_') && is_array($roomData) && isset($roomData['product_id'])) {
                $productId = (string) $roomData['product_id'];
                    if ($allowedIds !== null && ! in_array($productId, $allowedIds, true)) {
                        continue;
                    }

                    $product = Product::find($productId);
                    if (!$product) continue;

                    $productStyle = (int) ($product->styles ?? 1);

                    // Update product info
                    $updateData = [
                        'full_booking_discount' => $roomData['full_booking_discount'] ?? null,
                        'is_in_stock' => $roomData['is_in_stock'] ?? true,
                    ];

                    if ($productStyle === 2) {
                        $updateData['price']                = $roomData['price'] ?? 0;
                        $updateData['default_checkin']      = $roomData['default_checkin'] ?? '14:00';
                        $updateData['default_checkout']     = $roomData['default_checkout'] ?? '12:00';
                        $updateData['deposit_1_night']      = (int) ($roomData['deposit_1_night'] ?? 100);
                        $updateData['deposit_multi_night']  = (int) ($roomData['deposit_multi_night'] ?? 50);
                        $updateData['deposit_min_nights']   = (int) ($roomData['deposit_min_nights'] ?? 2);
                        $updateData['room_config'] = [
                            'max_free_guests' => (int) ($roomData['room_config_max_free_guests'] ?? 2),
                            'extra_guest_fee' => (int) preg_replace('/[^0-9]/', '', (string) ($roomData['room_config_extra_guest_fee'] ?? '50000')),
                        ];
                    }

                    $product->update($updateData);

                    // Style=2: lưu dateTimeSlots → room_time_slots qua timeslot type=date
                    if ($productStyle === 2 && isset($roomData['dateTimeSlots']) && is_array($roomData['dateTimeSlots'])) {
                        $existingDateIds = collect($roomData['dateTimeSlots'])->pluck('id')->filter()->toArray();
                        $dateSlotsToDelete = RoomTimeSlot::where('room_id', $productId)
                            ->whereHas('timeslot', fn($q) => $q->where('type', 'date'))
                            ->when(!empty($existingDateIds), fn($q) => $q->whereNotIn('id', $existingDateIds))
                            ->get();

                        foreach ($dateSlotsToDelete as $s) {
                            $s->promotions()->detach();
                            $s->delete();
                        }

                        foreach ($roomData['dateTimeSlots'] as $slot) {
                            if (empty($slot['date'])) continue;

                            $dateLabel = \Carbon\Carbon::parse($slot['date'])->format('Y-m-d');
                            $timeSlot  = TimeSlot::firstOrCreate(
                                ['label' => $dateLabel, 'type' => 'date'],
                                ['start_time' => null, 'end_time' => null]
                            );

                            $dateSlotData = [
                                'room_id'     => $productId,
                                'timeslot_id' => $timeSlot->id,
                                'price'       => $slot['price'] !== null
                                    ? (int) str_replace(['.', ','], '', $slot['price'])
                                    : null,
                                'status'      => 'available',
                                'checkin'     => $slot['checkin'] ?? null,
                                'checkout'    => $slot['checkout'] ?? null,
                            ];

                            $dateSlotModel = null;
                            if (!empty($slot['id'])) {
                                $dateSlotModel = RoomTimeSlot::find($slot['id']);
                                if ($dateSlotModel) $dateSlotModel->update($dateSlotData);
                            } else {
                                $dateSlotModel = RoomTimeSlot::create($dateSlotData);
                            }

                            if ($dateSlotModel) {
                                $allPromoIds = array_merge(
                                    array_map('intval', $slot['promotions'] ?? []),
                                    array_map('intval', $slot['surcharges'] ?? [])
                                );
                                $dateSlotModel->promotions()->sync($allPromoIds);
                                $dateSlotModel->coupons()->sync(array_map('intval', $slot['coupons'] ?? []));
                            }
                        }
                    }

                    // Style=1: lưu roomTimeSlots (timeslot_id NOT NULL)
                    if ($productStyle === 1 && isset($roomData['roomTimeSlots']) && is_array($roomData['roomTimeSlots'])) {
                        $existingIds = collect($roomData['roomTimeSlots'])->pluck('id')->filter()->toArray();
                        $slotsToDelete = RoomTimeSlot::where('room_id', $productId)
                            ->when(!empty($existingIds), fn($q) => $q->whereNotIn('id', $existingIds))
                            ->get();

                        foreach($slotsToDelete as $slotToDelete) {
                            $slotToDelete->promotions()->detach();
                            $slotToDelete->delete();
                        }

                        foreach ($roomData['roomTimeSlots'] as $slot) {
                            $slotData = [
                                'room_id' => $productId,
                                'timeslot_id' => $slot['timeslot_id'],
                                'price' => (int) str_replace(['.', ','], '', $slot['price']),
                                'over_night' => $slot['over_night'] ?? false,
                                'status' => $slot['status'] ?? 'available',
                            ];

                            $roomTimeSlotModel = null;

                            if (!empty($slot['id'])) {
                                $roomTimeSlotModel = RoomTimeSlot::find($slot['id']);
                                if ($roomTimeSlotModel) {
                                    $roomTimeSlotModel->update($slotData);
                                }
                            } else {
                                $roomTimeSlotModel = RoomTimeSlot::create($slotData);
                            }

                            if ($roomTimeSlotModel) {
                                $roomTimeSlotModel->promotions()->sync($slot['promotions'] ?? []);
                            }
                        }
                    }
                    $updated = true;
                }
            }

            if ($updated) {
                Notification::make()->title('Đã lưu thành công')->success()->send();
            } else {
                Notification::make()
                    ->title('Không có dữ liệu để lưu')
                    ->body('data keys: ' . implode(', ', array_keys($data)))
                    ->danger()->send();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Lỗi: ' . $e->getMessage())
                ->body($e->getFile() . ':' . $e->getLine())
                ->danger()
                ->persistent()
                ->send();
        }
    }

    /**
     * Ghi đè giá hàng loạt cho một khoảng ngày.
     * Được gọi từ Alpine.js trong blade: $wire.call('applyBulkOverride', ...)
     */
    public function applyBulkOverride(
        string  $roomId,
        string  $startDate,
        string  $endDate,
        int     $price,
        ?string $checkinTime,
        ?string $checkoutTime
    ): void {
        $product = Product::find($roomId);
        if (!$product || (int)($product->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $count = 0;
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->format('Y-m-d');

            $timeSlot = TimeSlot::firstOrCreate(
                ['label' => $dateStr, 'type' => 'date'],
                ['start_time' => null, 'end_time' => null]
            );

            RoomTimeSlot::updateOrCreate(
                ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
                ['price' => $price, 'status' => 'available', 'checkin' => $checkinTime, 'checkout' => $checkoutTime]
            );

            $count++;
        }

        // Reload form state để calendar cập nhật ngay
        $this->mount();

        Notification::make()
            ->title("Đã áp dụng giá cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()
            ->send();
    }

    /**
     * Lưu 1 ngày đơn lẻ trực tiếp (gọi từ Alpine saveDate).
     */
    public function saveSingleDate(
        string  $roomId,
        string  $date,
        int     $price,
        ?string $checkin,
        ?string $checkout
    ): void {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $timeSlot = TimeSlot::firstOrCreate(
            ['label' => $dateLabel, 'type' => 'date'],
            ['start_time' => null, 'end_time' => null]
        );

        // Dùng updateOrCreate theo room_id + timeslot_id để tránh lỗi FK khi update sai slot
        $slotModel = RoomTimeSlot::updateOrCreate(
            ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
            ['price' => $price, 'status' => 'available', 'checkin' => $checkin, 'checkout' => $checkout]
        );

        // Reload để calendar phản ánh ngay
        $this->mount();

        Notification::make()
            ->title('Đã lưu ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()
            ->send();
    }

    public function applyBulkPromotion(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $promotionIds
    ): void {
        $product = Product::find($roomId);

        if (!$product) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $count = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->format('Y-m-d');

            $timeSlot = TimeSlot::firstOrCreate(
                ['label' => $dateStr, 'type' => 'date'],
                ['start_time' => null, 'end_time' => null]
            );

            // firstOrCreate: nếu slot đã có → giữ nguyên giá ghi đè
            //                nếu chưa có → tạo mới với price=null (không ghi đè, dùng giá gốc phòng)
            $slotModel = RoomTimeSlot::firstOrCreate(
                ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
                ['price' => null, 'status' => 'available']
            );

            $slotModel->promotions()->sync($promotionIds);
            $count++;
        }

        $this->mount();

        Notification::make()
            ->title("Đã áp dụng khuyến mãi cho {$count} ngày")
            ->body(
                \Carbon\Carbon::parse($startDate)->format('d/m/Y')
                . ' → '
                . \Carbon\Carbon::parse($endDate)->format('d/m/Y')
            )
            ->success()
            ->send();
    }

    /**
     * Gỡ ghi đè hàng loạt: xóa price, checkin, checkout trong khoảng ngày.
     * Promotion giữ nguyên. Slot không có promotion → xóa hẳn record.
     */
    public function removeBulkOverride(
        string $roomId,
        string $startDate,
        string $endDate
    ): void {
        $product = Product::find($roomId);
        if (!$product || (int)($product->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $slots = RoomTimeSlot::with(['timeslot', 'promotions'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date')
                ->whereBetween('label', [$start->format('Y-m-d'), $end->format('Y-m-d')]))
            ->get();

        $count = 0;
        foreach ($slots as $slot) {
            if ($slot->promotions->isEmpty()) {
                // Không có promotion → xóa hẳn slot
                $slot->delete();
            } else {
                // Có promotion → giữ slot nhưng xóa giá ghi đè & checkin/checkout
                $slot->update(['price' => null, 'checkin' => null, 'checkout' => null]);
            }
            $count++;
        }

        $this->mount();

        Notification::make()
            ->title("Đã gỡ ghi đè cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Xóa ghi đè của 1 ngày đơn lẻ.
     * Logic: slot có promotion → chỉ xóa price/checkin/checkout; không có → xóa hẳn record.
     */
    public function deleteSingleDate(string $roomId, string $date): void
    {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $slot = RoomTimeSlot::with('promotions')
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('label', $dateLabel)->where('type', 'date'))
            ->first();

        if ($slot) {
            if ($slot->promotions->isEmpty()) {
                $slot->delete();
            } else {
                $slot->update(['price' => null, 'checkin' => null, 'checkout' => null]);
            }
        }

        $this->mount();

        Notification::make()
            ->title('Đã xóa ghi đè ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ khuyến mãi hàng loạt cho khoảng ngày.
     * $promoIds rỗng = gỡ tất cả khuyến mãi.
     */
    public function removeBulkPromotion(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $promoIds = []
    ): void {
        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $slots = RoomTimeSlot::with(['timeslot', 'promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date')
                ->whereBetween('label', [$start->format('Y-m-d'), $end->format('Y-m-d')]))
            ->get();

        $count = 0;
        foreach ($slots as $slot) {
            if (empty($promoIds)) {
                $slot->promotions()->detach();
            } else {
                $slot->promotions()->detach($promoIds);
            }

            $slot->refresh();
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
            $count++;
        }

        $this->mount();

        Notification::make()
            ->title("Đã gỡ khuyến mãi cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ mã giảm giá hàng loạt cho khoảng ngày.
     * $couponIds rỗng = gỡ tất cả mã giảm giá.
     */
    public function removeBulkCoupon(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $couponIds = []
    ): void {
        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $slots = RoomTimeSlot::with(['timeslot', 'promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date')
                ->whereBetween('label', [$start->format('Y-m-d'), $end->format('Y-m-d')]))
            ->get();

        $count = 0;
        foreach ($slots as $slot) {
            if (empty($couponIds)) {
                $slot->coupons()->detach();
            } else {
                $slot->coupons()->detach($couponIds);
            }

            $slot->refresh();
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
            $count++;
        }

        $this->mount();

        Notification::make()
            ->title("Đã gỡ mã giảm giá cho {$count} ngày")
            ->body(\Carbon\Carbon::parse($startDate)->format('d/m/Y') . ' → ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ 1 khuyến mãi / phụ thu khỏi 1 ngày đơn lẻ.
     * Dùng chung cho cả KM (percentage/fixed) và phụ thu (increase_*).
     */
    public function removeSinglePromotion(string $roomId, string $date, int $promotionId): void
    {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $slot = RoomTimeSlot::with(['promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('label', $dateLabel)->where('type', 'date'))
            ->first();

        if ($slot) {
            $slot->promotions()->detach($promotionId);
            $slot->refresh();
            // Slot rỗng (không còn KM, coupon và không có giá ghi đè) → xóa hẳn
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
        }

        $this->mount();

        Notification::make()
            ->title('Đã gỡ khuyến mãi ngày ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Gỡ 1 mã giảm giá khỏi 1 ngày đơn lẻ.
     */
    public function removeSingleCoupon(string $roomId, string $date, int $couponId): void
    {
        $dateLabel = \Carbon\Carbon::parse($date)->format('Y-m-d');

        $slot = RoomTimeSlot::with(['promotions', 'coupons'])
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('label', $dateLabel)->where('type', 'date'))
            ->first();

        if ($slot) {
            $slot->coupons()->detach($couponId);
            $slot->refresh();
            // Slot rỗng → xóa hẳn
            if ($slot->promotions->isEmpty() && $slot->coupons->isEmpty() && $slot->price === null) {
                $slot->delete();
            }
        }

        $this->mount();

        Notification::make()
            ->title('Đã gỡ mã giảm giá ngày ' . \Carbon\Carbon::parse($date)->format('d/m/Y'))
            ->success()->send();
    }

    /**
     * Xóa tất cả ngày đặc biệt của phòng.
     */
    public function deleteAllDates(string $roomId): void    {
        $slots = RoomTimeSlot::with('promotions')
            ->where('room_id', $roomId)
            ->whereHas('timeslot', fn($q) => $q->where('type', 'date'))
            ->get();

        foreach ($slots as $slot) {
            $slot->promotions()->detach();
            $slot->delete();
        }

        $this->mount();

        Notification::make()
            ->title('Đã xóa tất cả ngày đặc biệt')
            ->success()->send();
    }

    /**
     * Áp mã giảm giá hàng loạt cho khoảng ngày.
     * Lưu vào pivot coupon_room_time_slot.
     */
    public function applyBulkCoupon(
        string $roomId,
        string $startDate,
        string $endDate,
        array  $couponIds
    ): void {
        $product = Product::find($roomId);
        if (!$product || (int)($product->styles ?? 1) !== 2) {
            Notification::make()->title('Phòng không hợp lệ')->danger()->send();
            return;
        }

        $start = \Carbon\Carbon::parse($startDate)->startOfDay();
        $end   = \Carbon\Carbon::parse($endDate)->startOfDay();

        if ($start->gt($end)) {
            Notification::make()->title('Ngày bắt đầu phải nhỏ hơn ngày kết thúc')->warning()->send();
            return;
        }

        $basePrice = (int) ($product->price ?? 0);
        $count = 0;

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->format('Y-m-d');

            $timeSlot = TimeSlot::firstOrCreate(
                ['label' => $dateStr, 'type' => 'date'],
                ['start_time' => null, 'end_time' => null]
            );

            // Tạo slot nếu chưa có (giữ giá ghi đè nếu đã có)
            $slotModel = RoomTimeSlot::firstOrCreate(
                ['room_id' => $roomId, 'timeslot_id' => $timeSlot->id],
                ['price' => null, 'status' => 'available']
            );

            // Sync coupons vào pivot coupon_room_time_slot
            foreach ($couponIds as $couponId) {
                $slotModel->coupons()->syncWithoutDetaching([$couponId]);
            }

            $count++;
        }

        $this->mount();

        Notification::make()
            ->title("Đã áp mã giảm giá cho {$count} ngày")
            ->body(
                \Carbon\Carbon::parse($startDate)->format('d/m/Y')
                . ' → '
                . \Carbon\Carbon::parse($endDate)->format('d/m/Y')
            )
            ->success()->send();
    }
}