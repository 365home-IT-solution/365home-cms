<?php

namespace Modules\Book\App\Filament\Traits;

use App\Services\PriceBoardSyncService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\Grid;
use Filament\Notifications\Notification;
use Modules\Promotion\App\Models\Coupon;
use Modules\Promotion\App\Models\Promotion;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\TimeSlot;
use Filament\Forms\Get;
use Modules\Category\Entities\Categorizable;
use Modules\DataPermission\Entities\UserBranchPermission;

trait HasBookingHeaderActions
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('block_calendar')
                ->label('Tô đen / Khóa lịch')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->action(function () {
                    $this->dispatch('open-block-timeslot-modal');
                }),

            Action::make('create_room')
                ->label('Tạo phòng mới')
                ->icon('heroicon-o-plus-circle')
                ->url(fn () => route('filament.admin.resources.products.create'))
                ->openUrlInNewTab(),

            Action::make('create_coupon')
                ->label('Tạo Mã giảm giá')
                ->icon('heroicon-o-qr-code')
                ->color('success')
                ->slideOver()
                ->modalHeading('Tạo mã giảm giá mới')
                ->form([
                    Section::make('Thông tin cơ bản')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('code')
                                        ->label('Mã giảm giá')
                                        ->placeholder('VD: SUMMER2024')
                                        ->required()
                                        ->unique(Coupon::class, 'code')
                                        ->maxLength(50)
                                        ->alphaNum()
                                        ->helperText('Chỉ chứa chữ cái và số, tự động viết hoa'),

                                    TextInput::make('name')
                                        ->label('Tên mã giảm giá')
                                        ->required()
                                        ->maxLength(255),
                                ]),

                            Textarea::make('description')
                                ->label('Mô tả')
                                ->rows(3)
                                ->maxLength(500),
                        ]),

                    Section::make('Giá trị giảm giá')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    Select::make('type')
                                        ->label('Loại giảm giá')
                                        ->required()
                                        ->options([
                                            'percentage' => 'Phần trăm (%)',
                                            'fixed' => 'Số tiền cố định (VNĐ)',
                                        ])
                                        ->default('percentage')
                                        ->live()
                                        ->afterStateUpdated(fn ($state, callable $set) => $set('value', null)),

                                    TextInput::make('value')
                                        ->label(fn (Get $get) => $get('type') === 'percentage' ? 'Giá trị (%)' : 'Giá trị (VNĐ)')
                                        ->required()
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn (Get $get) => $get('type') === 'percentage' ? 100 : null)
                                        ->suffix(fn (Get $get) => $get('type') === 'percentage' ? '%' : 'VNĐ'),

                                    TextInput::make('max_discount')
                                        ->label('Giảm tối đa (VNĐ)')
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix('VNĐ')
                                        ->helperText('Để trống nếu không giới hạn')
                                        ->visible(fn (Get $get) => $get('type') === 'percentage'),
                                ]),

                            TextInput::make('min_order_value')
                                ->label('Giá trị đơn hàng tối thiểu (VNĐ)')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('VNĐ')
                                ->helperText('Để trống nếu không yêu cầu'),
                        ]),

                    Section::make('Phạm vi áp dụng')
                        ->description('Chọn phạm vi áp dụng mã giảm giá')
                        ->schema([
                            Select::make('apply_type')
                                ->label('Áp dụng cho')
                                ->required()
                                ->options([
                                    'all_rooms' => '🌐 Tất cả khung giờ của tất cả phòng',
                                    'specific_room' => '🏠 Tất cả khung giờ của 1 phòng cụ thể',
                                    'specific_slot' => '🎯 Các khung giờ cụ thể',
                                ])
                                ->default('all_rooms')
                                ->live()
                                ->afterStateUpdated(function ($state, callable $set) {
                                    $set('room_id', null);
                                    $set('room_time_slot_ids', []);
                                }),

                            Select::make('room_id')
                                ->label('Chọn phòng')
                                ->options(fn () => $this->allowedRoomOptions())
                                ->searchable()
                                ->preload()
                                ->required()
                                ->visible(fn (Get $get) => in_array($get('apply_type'), ['specific_room', 'specific_slot']))
                                ->live()
                                ->afterStateUpdated(fn (callable $set) => $set('room_time_slot_ids', [])),

                            Select::make('room_time_slot_ids')
                                ->label('Chọn khung giờ')
                                ->multiple()
                                ->searchable()
                                ->preload()
                                ->required()
                                ->options(function (Get $get) {
                                    $roomId = $get('room_id');
                                    if (!$roomId) {
                                        return [];
                                    }

                                    return RoomTimeSlot::where('room_id', $roomId)
                                        ->with('timeSlot')
                                        ->get()
                                        ->mapWithKeys(fn ($slot) => [
                                            $slot->id => $slot->timeSlot->label . ' - ' . number_format($slot->price, 0, ',', '.') . ' VNĐ'
                                        ]);
                                })
                                ->visible(fn (Get $get) => $get('apply_type') === 'specific_slot')
                                ->helperText('Có thể chọn nhiều khung giờ'),
                        ]),

                    Section::make('Giới hạn sử dụng & Thời gian')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    TextInput::make('usage_limit')
                                        ->label('Giới hạn số lần sử dụng')
                                        ->numeric()
                                        ->minValue(1)
                                        ->helperText('Để trống nếu không giới hạn'),

                                    Toggle::make('is_active')
                                        ->label('Kích hoạt')
                                        ->default(true)
                                        ->inline(false),
                                ]),

                            Grid::make(2)
                                ->schema([
                                    DateTimePicker::make('start_at')
                                        ->label('Ngày bắt đầu')
                                        ->required()
                                        ->default(now())
                                        ->native(false)
                                        ->seconds(false)
                                        ->timezone('Asia/Ho_Chi_Minh')
                                        ->displayFormat('d/m/Y H:i'),

                                    DateTimePicker::make('end_at')
                                        ->label('Ngày kết thúc')
                                        ->after('start_at')
                                        ->native(false)
                                        ->seconds(false)
                                        ->timezone('Asia/Ho_Chi_Minh')
                                        ->displayFormat('d/m/Y H:i')
                                        ->helperText('Để trống nếu không giới hạn thời gian'),
                                ]),
                        ]),
                ])
                ->action(function (array $data) {
                    try {
                        // Tạo coupon
                        $coupon = Coupon::create([
                            'code'            => strtoupper($data['code']),
                            'name'            => $data['name'],
                            'description'     => $data['description'] ?? null,
                            'type'            => $data['type'],
                            'value'           => $data['value'],
                            'apply_type'      => $data['apply_type'],
                            'room_id'         => $data['room_id'] ?? null,
                            'min_order_value' => $data['min_order_value'] ?? null,
                            'max_discount'    => $data['max_discount'] ?? null,
                            'usage_limit'     => $data['usage_limit'] ?? null,
                            'start_at'        => $data['start_at'],
                            'end_at'          => $data['end_at'] ?? null,
                            'is_active'       => $data['is_active'] ?? true,
                            'created_by'      => auth()->id(),
                        ]);

                        // Nếu là specific_slot, gắn các room_time_slot
                        if ($data['apply_type'] === 'specific_slot' && !empty($data['room_time_slot_ids'])) {
                            $coupon->roomTimeSlots()->attach($data['room_time_slot_ids']);
                        }

                        Notification::make()
                            ->title('✅ Tạo mã giảm giá thành công!')
                            ->body("Mã giảm giá **{$coupon->code}** đã được tạo.")
                            ->success()
                            ->send();

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('❌ Lỗi khi tạo mã giảm giá')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('create_promotion')
                ->label('Tạo Ưu đãi')
                ->icon('heroicon-o-ticket')
                ->color('warning')
                ->slideOver()
                ->modalHeading('Tạo khuyến mãi mới')
                ->form([
                    Section::make('Thông tin khuyến mãi')
                        ->schema([
                            TextInput::make('name')
                                ->label('Tên khuyến mãi')
                                ->required()
                                ->maxLength(255),

                            Textarea::make('description')
                                ->label('Mô tả')
                                ->rows(3)
                                ->maxLength(500)
                                ->placeholder('Ví dụ: Áp dụng cho các khung giờ từ 14h - 17h...'),

                            TextInput::make('lable_client')
                                ->label('Nhãn hiển thị cho khách')
                                ->placeholder('VD: ⭐ Giờ vàng, 🔥 Flash Sale')
                                ->helperText('Có thể dùng emoji: ⭐ 🔥 💎 ⚡ 🎉')
                                ->maxLength(100),
                        ]),

                    Section::make('Cấu hình khuyến mãi')
                        ->schema([
                            Grid::make(3)
                                ->schema([
                                    Select::make('type')
                                        ->label('Loại khuyến mãi')
                                        ->required()
                                        ->native(false)
                                        ->options([
                                            'percentage'          => 'Giảm theo % (%)',
                                            'fixed'               => 'Giảm cố định (VNĐ)',
                                            'increase_percentage' => 'Tăng theo % (%)',
                                            'increase_fixed'      => 'Tăng cố định (VNĐ)',
                                        ])
                                        ->default('percentage')
                                        ->live(),

                                    TextInput::make('value')
                                        ->label(fn (Get $get) => in_array($get('type'), ['increase_percentage', 'increase_fixed']) ? 'Giá trị tăng' : 'Giá trị giảm')
                                        ->required()
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(fn (Get $get) => in_array($get('type'), ['percentage', 'increase_percentage']) ? 100 : null)
                                        ->suffix(fn (Get $get) => in_array($get('type'), ['percentage', 'increase_percentage']) ? '%' : 'VNĐ'),

                                    Toggle::make('is_active')
                                        ->label('Kích hoạt')
                                        ->default(true)
                                        ->inline(false),
                                ]),
                        ]),

                    Section::make('Thời gian áp dụng')
                        ->schema([
                            Grid::make(2)
                                ->schema([
                                    DateTimePicker::make('start_at')
                                        ->label('Bắt đầu')
                                        ->required()
                                        ->default(now())
                                        ->native(false)
                                        ->seconds(false)
                                        ->timezone('Asia/Ho_Chi_Minh')
                                        ->displayFormat('d/m/Y H:i'),

                                    DateTimePicker::make('end_at')
                                        ->label('Kết thúc')
                                        ->required()
                                        ->after('start_at')
                                        ->native(false)
                                        ->seconds(false)
                                        ->timezone('Asia/Ho_Chi_Minh')
                                        ->displayFormat('d/m/Y H:i'),
                                ]),
                        ]),
                ])
                ->action(function (array $data) {
                    try {
                        Promotion::create([
                            'name'         => $data['name'],
                            'description'  => $data['description'] ?? null,
                            'lable_client' => $data['lable_client'] ?? null,
                            'type'         => $data['type'],
                            'value'        => $data['value'],
                            'start_at'     => $data['start_at'],
                            'end_at'       => $data['end_at'],
                            'is_active'    => $data['is_active'] ?? true,
                            'created_by'   => auth()->id(),
                        ]);

                        Notification::make()
                            ->title('✅ Tạo khuyến mãi thành công!')
                            ->body("Khuyến mãi **{$data['name']}** đã được tạo.")
                            ->success()
                            ->send();

                    } catch (\Exception $e) {
                        Notification::make()
                            ->title('❌ Lỗi khi tạo khuyến mãi')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            Action::make('bulk_price_update')
                ->label('Sửa giá hàng loạt')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                ->modalHeading('Sửa giá hàng loạt')
                ->modalDescription('Tích chọn phòng cần đổi giá rồi áp 1 giá/mức % — ghi thẳng xuống hệ thống ngay khi bấm Áp dụng, không tạo bảng giá nào.')
                ->modalSubmitActionLabel('Áp dụng')
                ->form([
                    Select::make('room_style')
                        ->label('Kiểu phòng')
                        ->options([1 => 'Theo Khung Giờ', 2 => 'Theo Ngày'])
                        ->default(1)
                        ->required()
                        ->live(),

                    CheckboxList::make('room_ids')
                        ->label('Phòng')
                        ->options(fn (Get $get) => Product::where('is_activated', true)->where('styles', (int) ($get('room_style') ?? 1))->orderBy('name')->pluck('name', 'id'))
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])
                        ->required()
                        ->columnSpanFull(),

                    Section::make('Giá cần đổi')
                        ->schema([
                            Select::make('apply_mode')
                                ->label('Kiểu áp dụng')
                                ->options([
                                    'price'   => 'Giá cụ thể (VNĐ)',
                                    'percent' => 'Điều chỉnh % trên giá đang có',
                                ])
                                ->default('price')
                                ->live()
                                ->required()
                                ->columnSpanFull(),

                            // Theo Ngày: chỉ có 1 giá/đêm duy nhất, không có khái niệm "khung giờ".
                            TextInput::make('value')
                                ->label(fn (Get $get) => $get('apply_mode') === 'percent' ? 'Mức % (+/-)' : 'Giá mới (VNĐ)')
                                ->numeric()
                                ->required(fn (Get $get) => (int) $get('room_style') === 2)
                                ->visible(fn (Get $get) => (int) $get('room_style') === 2)
                                ->columnSpanFull(),

                            // Theo Khung Giờ: nhiều dòng, mỗi dòng đổi giá 1 khung giờ riêng (VD khung
                            // 1 → giá A, khung 2 → giá B...) — 1 lần bấm Áp dụng là áp hết.
                            Repeater::make('slot_rules')
                                ->label('Các khung giờ cần đổi giá')
                                ->helperText('Thêm nhiều dòng để đổi giá nhiều khung giờ cùng lúc cho các phòng đã chọn.')
                                ->schema([
                                    Select::make('pick_mode')
                                        ->label('Chọn khung giờ theo')
                                        ->options([
                                            'position' => 'Vị trí (đầu/cuối/thứ N của mỗi phòng)',
                                            'specific' => 'Khung giờ cụ thể',
                                        ])
                                        ->default('position')
                                        ->live()
                                        ->columnSpan(1),

                                    Select::make('timeslot_id')
                                        ->label('Khung giờ')
                                        ->options(fn () => TimeSlot::where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))->pluck('label', 'id'))
                                        ->searchable()
                                        ->columnSpan(1)
                                        ->visible(fn (Get $get) => $get('pick_mode') === 'specific'),

                                    Select::make('position')
                                        ->label('Vị trí khung giờ')
                                        ->options([
                                            'first' => 'Khung đầu tiên (giờ sớm nhất)',
                                            'last'  => 'Khung cuối cùng (giờ muộn nhất)',
                                            'nth'   => 'Khung thứ N',
                                        ])
                                        ->default('first')
                                        ->live()
                                        ->columnSpan(1)
                                        ->visible(fn (Get $get) => $get('pick_mode') === 'position'),

                                    TextInput::make('position_n')
                                        ->label('N = ?')
                                        ->numeric()
                                        ->minValue(1)
                                        ->columnSpan(1)
                                        ->visible(fn (Get $get) => $get('pick_mode') === 'position' && $get('position') === 'nth'),

                                    TextInput::make('value')
                                        ->label(fn (Get $get) => $get('../../apply_mode') === 'percent' ? 'Mức % (+/-)' : 'Giá mới (VNĐ)')
                                        ->numeric()
                                        ->required()
                                        ->columnSpan(1),
                                ])
                                // Luôn 1 cột — đây là modal rộng cố định (~672px), lưới chia cột theo
                                // breakpoint màn hình (viewport) KHÔNG theo bề rộng modal thực tế, nên
                                // cứ chia nhiều cột là bị bóp chật/chữ bị cắt dù màn hình máy tính to.
                                // Mỗi field 1 dòng riêng, đủ rộng để không bị lệch/cắt chữ ở mọi kích
                                // thước màn hình, kể cả mobile.
                                ->columns(1)
                                ->defaultItems(1)
                                ->addActionLabel('Thêm khung giờ cần đổi')
                                ->columnSpanFull()
                                ->visible(fn (Get $get) => (int) $get('room_style') === 1),
                        ])
                        ->columns(['default' => 1, 'sm' => 2]),
                ])
                ->action(function (array $data) {
                    $count = $this->applyBulkPriceUpdate($data);

                    $this->fillFormData();

                    Notification::make()
                        ->title("Đã cập nhật giá cho {$count} phòng")
                        ->success()
                        ->send();
                }),

        ];
    }

    /** Ghi giá mới thẳng xuống products/room_time_slots cho các phòng đã tích chọn — không qua bảng
     *  giá nào, áp dụng ngay lập tức (đúng tinh thần "Hệ thống giá": sửa là có hiệu lực ngay). */
    private function applyBulkPriceUpdate(array $data): int
    {
        $style     = (int) $data['room_style'];
        $mode      = $data['apply_mode'];
        $slotRules = collect($data['slot_rules'] ?? [])
            ->filter(fn ($rule) => ($rule['value'] ?? null) !== null && $rule['value'] !== '')
            ->values();

        $rooms = Product::where('is_activated', true)
            ->where('styles', $style)
            ->whereIn('id', $data['room_ids'] ?? [])
            ->get();

        $count   = 0;
        $service = app(PriceBoardSyncService::class);

        foreach ($rooms as $room) {
            $before  = $service->snapshotPricing($room);
            $touched = false;

            if ($style === 2) {
                $value   = (float) ($data['value'] ?? 0);
                $current = (float) $room->price;
                $new     = $mode === 'percent' ? (int) round($current * (1 + $value / 100)) : (int) round($value);
                $room->update(['price' => $new]);
                $touched = true;
            } else {
                $slots = RoomTimeSlot::where('room_id', $room->id)
                    ->whereHas('timeSlot', fn ($q) => $q->where(fn ($q2) => $q2->whereNull('type')->orWhere('type', '!=', 'date')))
                    ->with('timeSlot')
                    ->get()
                    ->sortBy(fn ($s) => $s->timeSlot?->start_time ?? '99:99:99')
                    ->values();

                if ($slots->isEmpty()) {
                    continue;
                }

                // Áp TỪNG dòng đã khai báo — mỗi dòng chỉ đổi 1 vị trí/khung giờ cụ thể của phòng
                // này nên không ghi đè lẫn nhau (khung 1→A xong mới tới khung 2→B).
                foreach ($slotRules as $rule) {
                    $target = ($rule['pick_mode'] ?? 'position') === 'specific'
                        ? $slots->firstWhere('timeslot_id', $rule['timeslot_id'] ?? null)
                        : $slots->get(match ($rule['position'] ?? 'first') {
                            'last'  => $slots->count() - 1,
                            'nth'   => ((int) ($rule['position_n'] ?? 1)) - 1,
                            default => 0,
                        });

                    if (! $target) {
                        continue;
                    }

                    $value   = (float) $rule['value'];
                    $current = (float) $target->price;
                    $new     = $mode === 'percent' ? (int) round($current * (1 + $value / 100)) : (int) round($value);
                    $target->update(['price' => $new]);
                    $touched = true;
                }
            }

            if (! $touched) {
                continue;
            }

            $freshRoom = $room->fresh();
            $service->seedDefaultBoard($freshRoom);
            $service->logPriceChange($service->defaultBoard()->id, $freshRoom, $before, $service->snapshotPricing($freshRoom));
            $count++;
        }

        return $count;
    }

    private function allowedRoomOptions(): array
    {
        $user  = auth()->user();
        $query = Product::where('is_activated', true);

        if (! $user || $user->isSuperAdmin()) {
            return $query->pluck('name', 'id')->toArray();
        }

        $categoryIds = $user->allowedCategoryIds();
        if (empty($categoryIds)) {
            // Không thu hẹp thêm — Product đã tự lọc theo partner_id (BelongsToPartner).
            return $query->pluck('name', 'id')->toArray();
        }

        $allowedIds = Categorizable::where('categorizable_type', Product::class)
            ->whereIn('category_id', $categoryIds)
            ->distinct()
            ->pluck('categorizable_id');

        return $query->whereIn('id', $allowedIds)->pluck('name', 'id')->toArray();
    }
}