<?php

declare(strict_types=1);

namespace Modules\Book\App\Filament\Resources\PriceBoardResource\Forms;

use App\Models\Partner;
use App\Models\User;
use App\Services\RoomSlotRandomizer;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Modules\Category\Entities\Category;
use Modules\Product\App\Models\PriceBoard;
use Modules\Product\App\Models\PriceBoardSheet;
use Modules\Product\App\Models\PriceTemplate;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\TimeSlot;

/**
 * Form "Bảng giá" v2 trên admin web: một bảng gồm nhiều BỘ GIÁ (sheets), áp theo phòng / chi nhánh / toàn
 * đối tác — cùng nghiệp vụ với API /api/admin/price-boards (App\Services\PriceBoards\PriceBoardV2Service).
 * Khung giờ + giá của bộ giá điền được bằng cách chọn trong bảng giá mẫu có sẵn hoặc random.
 */
class PriceBoardSheetsForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin bảng giá')
                ->schema([
                    Select::make('partner_id')
                        ->label('Đối tác')
                        ->options(fn () => Partner::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->live()
                        ->required(fn (Get $get) => static::needsPartnerChoice() && ! $get('is_template'))
                        ->visible(fn (Get $get) => static::needsPartnerChoice() && ! $get('is_template'))
                        ->afterStateUpdated(function (Set $set) {
                            $set('branch_ids', []);
                            $set('product_ids', []);
                        })
                        ->helperText('Super_admin chọn đối tác sở hữu bảng giá.'),

                    TextInput::make('name')->label('Tên bảng giá')->required()->maxLength(255)
                        ->placeholder('VD: Giá thường 2026, Giá Tết'),

                    Select::make('scope')
                        ->label('Phạm vi áp dụng')
                        ->options([
                            PriceBoard::SCOPE_ROOMS    => 'Một số phòng',
                            PriceBoard::SCOPE_BRANCHES => 'Một số chi nhánh (kể cả phòng tạo sau)',
                            PriceBoard::SCOPE_PARTNER  => 'Toàn bộ chi nhánh của đối tác (kể cả phòng tạo sau)',
                        ])
                        ->default(PriceBoard::SCOPE_ROOMS)
                        ->required(fn (Get $get) => ! $get('is_template'))
                        ->visible(fn (Get $get) => ! $get('is_template'))
                        ->live()
                        ->columnSpanFull(),

                    Select::make('branch_ids')
                        ->label('Chi nhánh')
                        ->multiple()
                        ->searchable()
                        ->options(fn ($livewire) => static::branchOptions(static::partnerId($livewire)))
                        ->required(fn (Get $get) => $get('scope') === PriceBoard::SCOPE_BRANCHES && ! $get('is_template'))
                        ->visible(fn (Get $get) => $get('scope') === PriceBoard::SCOPE_BRANCHES && ! $get('is_template'))
                        ->placeholder(fn ($livewire) => static::partnerId($livewire) ? null : 'Chọn đối tác trước')
                        ->noSearchResultsMessage('Không có chi nhánh — kiểm tra đã chọn đối tác chưa.')
                        ->columnSpanFull(),

                    Select::make('product_ids')
                        ->label('Phòng của bảng')
                        ->multiple()
                        ->searchable()
                        ->options(fn ($livewire) => static::roomOptions(static::partnerId($livewire)))
                        ->visible(fn (Get $get) => $get('scope') === PriceBoard::SCOPE_ROOMS && ! $get('is_template'))
                        ->placeholder(fn ($livewire) => static::partnerId($livewire) ? null : 'Chọn đối tác trước')
                        ->helperText('Có thể bỏ trống — phòng được gán ngay ở từng bộ giá bên dưới.')
                        ->columnSpanFull(),

                    Toggle::make('is_active')
                        ->label('Kích hoạt')
                        ->default(false)
                        ->inline(false)
                        ->helperText('Bật là có hiệu lực NGAY cho các phòng thuộc bảng (nếu hôm nay nằm trong khoảng ngày). Nên lưu ở trạng thái tắt, kiểm tra rồi mới bật.'),

                    DatePicker::make('start_date')->label('Ngày bắt đầu')->native(false)->displayFormat('d/m/Y')->live(),
                    DatePicker::make('end_date')->label('Ngày kết thúc')->native(false)->displayFormat('d/m/Y')->afterOrEqual('start_date'),

                    \Filament\Forms\Components\TextInput::make('priority')
                        ->label('Độ ưu tiên')
                        ->numeric()->minValue(0)->maxValue(100)->default(0)
                        ->visible(fn () => PriceBoard::phase2Enabled())
                        ->helperText('Số lớn hơn thắng khi nhiều bảng cùng áp một ngày (0–100).'),

                    \Filament\Forms\Components\CheckboxList::make('days_of_week')
                        ->label('Áp dụng các thứ')
                        ->options([1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 7 => 'Chủ nhật'])
                        ->columns(4)
                        ->visible(fn () => PriceBoard::phase2Enabled())
                        ->helperText('Bỏ trống = mọi ngày. Thứ của khung qua đêm tính theo ngày bắt đầu khung.')
                        ->columnSpanFull(),

                    Textarea::make('note')->label('Ghi chú')->rows(2)->columnSpanFull(),
                ])->columns(2),

            Section::make('Bộ giá')
                ->description('Mỗi bộ giá là một cách tính giá cho một nhóm phòng. Phòng nào có tên trong "Phòng áp dụng" của bộ thì nhận bộ đó; phòng còn lại nhận bộ MẶC ĐỊNH cùng loại (theo giờ / theo ngày).')
                ->schema([
                    Repeater::make('sheets')
                        ->hiddenLabel()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Thêm bộ giá')
                        ->collapsible()
                        ->itemLabel(fn (array $state) => ($state['name'] ?? null) ?: 'Bộ giá mới')
                        ->schema(static::sheetSchema())
                        ->columnSpanFull(),
                ]),

            Section::make('Bảng giá dựng sẵn (chỉ super_admin)')
                ->description('Lưu bảng này thành bảng giá dựng sẵn để đối tác chọn dùng. Bảng dựng sẵn không gắn phòng, không ghi đè giá.')
                ->schema([
                    Toggle::make('is_template')->label('Là bảng giá dựng sẵn')->live()->inline(false),
                    TextInput::make('code')->label('Mã (code)')->maxLength(50)
                        ->required(fn (Get $get) => (bool) $get('is_template'))
                        ->visible(fn (Get $get) => (bool) $get('is_template')),
                    Select::make('default_scope')->label('Phạm vi mặc định')
                        ->options([
                            PriceBoard::SCOPE_PARTNER  => 'Toàn đối tác',
                            PriceBoard::SCOPE_BRANCHES => 'Theo chi nhánh',
                            PriceBoard::SCOPE_ROOMS    => 'Một số phòng',
                        ])
                        ->default(PriceBoard::SCOPE_PARTNER)
                        ->visible(fn (Get $get) => (bool) $get('is_template')),
                    Toggle::make('requires_dates')->label('Bắt buộc chọn ngày khi dùng')->inline(false)
                        ->visible(fn (Get $get) => (bool) $get('is_template')),
                    Textarea::make('description')->label('Mô tả')->rows(2)->columnSpanFull()
                        ->visible(fn (Get $get) => (bool) $get('is_template')),
                ])
                ->columns(2)
                ->visible(fn () => static::isSuperAdmin()),
        ]);
    }

    /** @return list<\Filament\Forms\Components\Component> */
    private static function sheetSchema(): array
    {
        return [
            Hidden::make('id'),

            TextInput::make('name')->label('Tên bộ giá')->required()->maxLength(255)
                ->placeholder('Phòng tiêu chuẩn, Phòng VIP, Phòng theo ngày...')->live(onBlur: true),

            Select::make('pricing_mode')
                ->label('Kiểu')
                ->options([
                    PriceBoard::MODE_OVERRIDE   => 'Thay thế giá cụ thể',
                    PriceBoard::MODE_ADJUSTMENT => 'Điều chỉnh % / số tiền trên giá gốc',
                ])
                ->default(PriceBoard::MODE_OVERRIDE)
                ->required()
                ->live(),

            Select::make('room_style')
                ->label('Loại phòng')
                ->options([1 => 'Theo giờ (theo khung giờ)', 2 => 'Theo ngày (giá/đêm)'])
                ->required(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_OVERRIDE)
                ->helperText('Kiểu "điều chỉnh" để trống = áp cho cả hai loại phòng.')
                ->live(),

            Toggle::make('is_default')->label('Bộ mặc định cho loại phòng này')->inline(false)
                ->helperText('Phòng không được gán đích danh sẽ nhận bộ mặc định cùng loại.'),

            Select::make('product_ids')
                ->label('Phòng áp dụng (gán đích danh)')
                ->multiple()
                ->searchable()
                ->options(fn ($livewire) => static::roomOptions(static::partnerId($livewire)))
                ->placeholder(fn ($livewire) => static::partnerId($livewire) ? null : 'Chọn đối tác trước')
                ->visible(fn (Get $get) => ! $get('../../is_template'))
                ->columnSpanFull(),

            // ── Theo giờ ────────────────────────────────────────────────────
            Group::make([
                Section::make('Khung giờ + giá')
                    ->description('Điền nhanh bằng bảng giá mẫu hoặc random, rồi sửa giá từng khung.')
                    ->compact()
                    ->schema([
                        Select::make('_slot_source')
                            ->label('Cách điền')
                            ->options(['template' => 'Chọn trong bảng giá mẫu', 'random' => 'Random'])
                            ->default('template')
                            ->live()
                            ->dehydrated(false),
                        Select::make('_template_id')
                            ->label('Bảng giá mẫu')
                            ->options(fn ($livewire) => static::templateOptions(static::partnerId($livewire)))
                            ->dehydrated(false)
                            ->visible(fn (Get $get) => ($get('_slot_source') ?? 'template') === 'template'),
                        TextInput::make('_random_count')
                            ->label('Số khung giờ')
                            ->numeric()
                            ->integer()
                            ->minValue(RoomSlotRandomizer::MIN_COUNT)
                            ->maxValue(RoomSlotRandomizer::MAX_COUNT)
                            ->default(RoomSlotRandomizer::DEFAULT_COUNT)
                            ->dehydrated(false)
                            ->visible(fn (Get $get) => $get('_slot_source') === 'random'),
                        Actions::make([
                            Action::make('fill_slots')
                                ->label('Điền khung giờ + giá')
                                ->icon('heroicon-o-sparkles')
                                ->color('gray')
                                ->action(function (Get $get, Set $set, $livewire) {
                                    // Random tạo khung giờ mới: chỉ cho bảng dựng sẵn (khung hệ thống) hoặc khi đã có đối tác,
                                    // tránh tạo khung dùng chung cho mọi đối tác ngoài ý muốn.
                                    if (! static::partnerId($livewire) && ! $get('../../is_template')
                                        && ($get('_slot_source') ?? 'template') === 'random') {
                                        Notification::make()->title('Chọn đối tác trước khi random khung giờ.')->warning()->send();

                                        return;
                                    }

                                    $rows = static::slotRows(
                                        static::partnerId($livewire),
                                        (string) ($get('_slot_source') ?? 'template'),
                                        $get('_template_id'),
                                        (int) ($get('_random_count') ?: RoomSlotRandomizer::DEFAULT_COUNT),
                                    );

                                    if ($rows === null) {
                                        Notification::make()->title('Chọn bảng giá mẫu trước.')->warning()->send();

                                        return;
                                    }

                                    $set('time_slots', $rows);
                                }),
                        ]),
                        Repeater::make('time_slots')
                            ->hiddenLabel()
                            ->addActionLabel('Thêm khung giờ')
                            ->schema([
                                Select::make('timeslot_id')
                                    ->label('Khung giờ')
                                    ->options(fn ($livewire) => static::timeSlotOptions(static::partnerId($livewire)))
                                    ->searchable()
                                    ->required()
                                    ->distinct(),
                                TextInput::make('price')->label('Giá')->numeric()->minValue(0)->required()->suffix('₫'),
                                Toggle::make('over_night')->label('Qua đêm')->default(false)->inline(false),
                            ])
                            ->columns(3),
                    ]),

                TextInput::make('full_booking_discount')
                    ->label('Giảm khi đặt cả ngày')
                    ->placeholder('VD: 20%')
                    ->helperText('Để trống = giữ giá trị hiện tại của từng phòng.'),

                TextInput::make('full_day_price')
                    ->label('Giá cả ngày')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999999)
                    ->suffix('₫')
                    ->placeholder('VD: 450000')
                    ->helperText('Khách chọn ĐỦ mọi khung giờ trong ngày thì tính đúng giá này (thay cho "Giảm khi đặt cả ngày"). Phải thấp hơn tổng giá các khung. Để trống = giữ giá cả ngày hiện tại của từng phòng; nhập 0 = bỏ giá cả ngày của các phòng này.'),

                Repeater::make('bulk_discount_rules')
                    ->label('Giảm theo số khung đặt')
                    ->addActionLabel('Thêm mức giảm')
                    ->schema([
                        TextInput::make('slots')->label('Từ số khung')->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('discount')->label('Giảm (%)')->numeric()->minValue(0)->maxValue(100)->required(),
                    ])
                    ->columns(2),

                TextInput::make('room_config_max_free_guests')->label('Số khách miễn phí')->numeric()->integer()->minValue(0),
                TextInput::make('room_config_extra_guest_fee')->label('Phụ thu mỗi khách thêm')->numeric()->minValue(0)->suffix('₫'),
            ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_OVERRIDE && (int) $get('room_style') === 1),

            // ── Theo ngày ───────────────────────────────────────────────────
            Group::make([
                // Cách nhập giá: một giá cho mọi ngày, hoặc giá riêng cho từng thứ (cần bật giai đoạn 2 của bảng giá).
                Select::make('price_mode')
                    ->label('Cách nhập giá')
                    ->options(['single' => 'Một giá cho mọi ngày', 'weekday' => 'Giá theo từng thứ trong tuần'])
                    ->default('single')
                    ->selectablePlaceholder(false)
                    ->live()
                    ->visible(fn () => PriceBoard::phase2Enabled())
                    ->helperText('Ngày đặc biệt đã đặt giá riêng ở lịch phòng luôn được ưu tiên hơn giá theo thứ.')
                    ->columnSpanFull(),
                TextInput::make('price')->label('Giá một đêm')->numeric()->minValue(0)->suffix('₫')
                    ->required(fn (Get $get) => $get('price_mode') !== 'weekday')
                    ->visible(fn (Get $get) => $get('price_mode') !== 'weekday')
                    ->helperText(fn (Get $get) => ! PriceBoard::phase2Enabled() && array_filter(array_map(fn ($day) => $get('weekday_price_' . $day), range(1, 7)))
                        ? 'Bộ giá này đã có giá theo từng thứ nhưng giai đoạn 2 đang tắt: giá theo thứ tạm chưa có tác dụng, mọi đêm tính theo giá này. Bật lại giai đoạn 2 (PRICE_BOARDS_PHASE2) để dùng lại giá theo thứ.'
                        : null),
                \Filament\Forms\Components\Grid::make(4)->schema(array_map(
                    fn (int $day, string $label) => TextInput::make('weekday_price_' . $day)->label($label)->numeric()->integer()->minValue(0)->maxValue(\App\Services\PriceBoards\PriceBoardV2Service::MAX_WEEKDAY_PRICE)->suffix('₫')->required(),
                    array_keys(\App\Services\PriceBoards\PriceBoardV2Service::WEEKDAY_LABELS),
                    array_values(\App\Services\PriceBoards\PriceBoardV2Service::WEEKDAY_LABELS),
                ))
                    ->columnSpanFull()
                    ->visible(fn (Get $get) => PriceBoard::phase2Enabled() && $get('price_mode') === 'weekday'),
                TimePicker::make('default_checkin')->label('Giờ nhận')->seconds(false)->default('14:00'),
                TimePicker::make('default_checkout')->label('Giờ trả')->seconds(false)->default('12:00'),
                TextInput::make('deposit_min_nights')->label('Cọc từ số đêm')->numeric()->integer()->minValue(1),
                TextInput::make('deposit_multi_night')->label('Cọc nhiều đêm (%)')->numeric()->integer()->minValue(0)->maxValue(100),
            ])
                ->columns(3)
                ->columnSpanFull()
                ->visible(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_OVERRIDE && (int) $get('room_style') === 2),

            // ── Điều chỉnh ──────────────────────────────────────────────────
            Group::make([
                Select::make('adjustment_type')
                    ->label('Kiểu điều chỉnh')
                    ->options(['percentage' => 'Theo % giá gốc', 'fixed' => 'Số tiền cố định'])
                    ->default('percentage')
                    ->required()
                    ->live(),
                TextInput::make('adjustment_value')
                    ->label(fn (Get $get) => $get('adjustment_type') === 'fixed' ? 'Số tiền (+/- VNĐ)' : 'Phần trăm (+/- %)')
                    ->helperText('Số âm là giảm: -10 = giảm 10%. Làm tròn 1.000đ, không bao giờ âm.')
                    ->numeric()
                    ->required(),
                Select::make('adjustment_timeslot_ids')
                    ->label('Chỉ chỉnh các khung này')
                    ->multiple()
                    ->searchable()
                    ->options(fn ($livewire) => static::timeSlotOptions(static::partnerId($livewire)))
                    ->helperText('Bỏ trống = mọi khung giờ.')
                    ->columnSpanFull(),
            ])
                ->columns(2)
                ->columnSpanFull()
                ->visible(fn (Get $get) => $get('pricing_mode') === PriceBoard::MODE_ADJUSTMENT),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Dữ liệu form ⇄ spec của service
    // ════════════════════════════════════════════════════════════════════

    /**
     * Dữ liệu form → body (cùng định dạng API) cho PriceBoardV2Service::specFromRequest().
     *
     * @return array<string,mixed>
     */
    public static function toRequest(array $data): array
    {
        $req = [
            'name'       => $data['name'] ?? null,
            'note'       => $data['note'] ?? null,
            'scope'      => $data['scope'] ?? PriceBoard::SCOPE_ROOMS,
            'is_active'  => (bool) ($data['is_active'] ?? false),
            'start_date' => ! empty($data['start_date']) ? \Illuminate\Support\Carbon::parse($data['start_date'])->toDateString() : null,
            'end_date'   => ! empty($data['end_date']) ? \Illuminate\Support\Carbon::parse($data['end_date'])->toDateString() : null,
            'priority'     => PriceBoard::phase2Enabled() ? (int) ($data['priority'] ?? 0) : null,
            'days_of_week' => PriceBoard::phase2Enabled() ? array_values(array_map('intval', $data['days_of_week'] ?? [])) : null,
            'branch_ids'  => array_values(array_map('intval', $data['branch_ids'] ?? [])),
            'product_ids' => array_values(array_map('strval', $data['product_ids'] ?? [])),
            'sheets'      => [],
        ];

        foreach (array_values($data['sheets'] ?? []) as $i => $sheet) {
            $mode = $sheet['pricing_mode'] ?? PriceBoard::MODE_OVERRIDE;

            $row = [
                'id'           => ! empty($sheet['id']) ? (int) $sheet['id'] : null,
                'name'         => $sheet['name'] ?? ('Bộ giá ' . ($i + 1)),
                'pricing_mode' => $mode,
                'room_style'   => filled($sheet['room_style'] ?? null) ? (int) $sheet['room_style'] : null,
                'is_default'   => (bool) ($sheet['is_default'] ?? false),
                'sort_order'   => $i,
                'product_ids'  => array_values(array_map('strval', $sheet['product_ids'] ?? [])),
            ];

            if ($mode === PriceBoard::MODE_ADJUSTMENT) {
                $row += [
                    'adjustment_type'         => $sheet['adjustment_type'] ?? null,
                    'adjustment_value'        => filled($sheet['adjustment_value'] ?? null) ? (float) $sheet['adjustment_value'] : null,
                    'adjustment_timeslot_ids' => array_values(array_map('intval', $sheet['adjustment_timeslot_ids'] ?? [])),
                ];
            } elseif ((int) ($row['room_style'] ?? 0) === 2) {
                // Giá theo thứ: 7 ô weekday_price_1…7 (1 = Thứ 2 … 7 = Chủ nhật). Chọn "một giá" thì gửi null để xoá giá theo thứ cũ.
                $weekday = null;

                if (PriceBoard::phase2Enabled() && ($sheet['price_mode'] ?? 'single') === 'weekday') {
                    $weekday = [];

                    foreach (array_keys(\App\Services\PriceBoards\PriceBoardV2Service::WEEKDAY_LABELS) as $day) {
                        $weekday[$day] = filled($sheet['weekday_price_' . $day] ?? null) ? (int) $sheet['weekday_price_' . $day] : null;
                    }
                }

                // Giai đoạn 2 tắt: form không có ô giá theo thứ nên KHÔNG gửi trường này (giữ nguyên giá theo thứ đã lưu, không xoá nhầm).
                if (PriceBoard::phase2Enabled()) {
                    $row['weekday_prices'] = $weekday;
                }

                $row += [
                    'price'               => $weekday === null && filled($sheet['price'] ?? null) ? (float) $sheet['price'] : null,
                    'default_checkin'     => $sheet['default_checkin'] ?? null,
                    'default_checkout'    => $sheet['default_checkout'] ?? null,
                    'deposit_min_nights'  => filled($sheet['deposit_min_nights'] ?? null) ? (int) $sheet['deposit_min_nights'] : null,
                    'deposit_multi_night' => filled($sheet['deposit_multi_night'] ?? null) ? (int) $sheet['deposit_multi_night'] : null,
                ];
            } else {
                $row['time_slots'] = array_values(array_map(fn ($s) => [
                    'timeslot_id' => (int) ($s['timeslot_id'] ?? 0) ?: null,
                    'price'       => filled($s['price'] ?? null) ? (int) $s['price'] : null,
                    'over_night'  => (bool) ($s['over_night'] ?? false),
                ], $sheet['time_slots'] ?? []));

                // Trường ưu đãi để trống = không khai báo (giữ giá trị hiện tại của phòng).
                if (filled($sheet['full_booking_discount'] ?? null)) {
                    $row['full_booking_discount'] = $sheet['full_booking_discount'];
                }

                // Giá cả ngày: có số (0 = xoá giá cả ngày của phòng) thì khai báo; để trống thì gửi null = bỏ khai báo.
                $row['full_day_price'] = filled($sheet['full_day_price'] ?? null) ? (int) $sheet['full_day_price'] : null;

                if (! empty($sheet['bulk_discount_rules'])) {
                    $row['bulk_discount_rules'] = array_values(array_map(
                        fn ($r) => ['slots' => (int) $r['slots'], 'discount' => (float) $r['discount']],
                        $sheet['bulk_discount_rules']
                    ));
                }

                if (filled($sheet['room_config_max_free_guests'] ?? null) || filled($sheet['room_config_extra_guest_fee'] ?? null)) {
                    $row['room_config'] = [
                        'max_free_guests' => (int) ($sheet['room_config_max_free_guests'] ?? 2),
                        'extra_guest_fee' => (int) ($sheet['room_config_extra_guest_fee'] ?? 0),
                    ];
                }
            }

            $req['sheets'][] = $row;
        }

        return $req;
    }

    /**
     * Bảng đã lưu → dữ liệu điền form.
     *
     * @param array<string,mixed> $spec  PriceBoardV2Service::specFromBoard()
     * @return array<string,mixed>
     */
    public static function toFormData(array $spec, PriceBoard $board): array
    {
        return [
            'partner_id'  => $board->partner_id,
            'name'        => $spec['name'],
            'note'        => $spec['note'],
            'scope'       => $spec['scope'],
            'branch_ids'  => $spec['branch_ids'],
            'product_ids' => $spec['product_ids'],
            'is_active'   => $spec['is_active'],
            'start_date'  => $spec['start_date'],
            'end_date'    => $spec['end_date'],
            'priority'     => (int) ($spec['priority'] ?? 0),
            'days_of_week' => array_map('strval', $spec['days_of_week'] ?? []),
            'is_template'    => (bool) $board->is_template,
            'code'           => $board->code,
            'default_scope'  => $board->default_scope,
            'requires_dates' => (bool) $board->requires_dates,
            'description'    => $board->description,
            'sheets'      => array_map(fn (array $s) => [
                'id'            => $s['id'],
                'name'          => $s['name'],
                'pricing_mode'  => $s['pricing_mode'],
                'room_style'    => $s['room_style'],
                'is_default'    => $s['is_default'],
                'product_ids'   => $s['product_ids'],
                'time_slots'    => array_map(fn ($t) => [
                    'timeslot_id' => $t['timeslot_id'], 'price' => $t['price'], 'over_night' => $t['over_night'],
                ], $s['time_slots']),
                'full_booking_discount'       => in_array('full_booking_discount', $s['fields_set'], true) ? $s['full_booking_discount'] : null,
                'full_day_price'              => in_array('full_day_price', $s['fields_set'], true) ? $s['full_day_price'] : null,
                'bulk_discount_rules'         => in_array('bulk_discount_rules', $s['fields_set'], true) ? ($s['bulk_discount_rules'] ?? []) : [],
                'room_config_max_free_guests' => in_array('room_config', $s['fields_set'], true) ? ($s['room_config']['max_free_guests'] ?? null) : null,
                'room_config_extra_guest_fee' => in_array('room_config', $s['fields_set'], true) ? ($s['room_config']['extra_guest_fee'] ?? null) : null,
                'price'               => $s['price'],
                'price_mode'          => PriceBoard::phase2Enabled() && ! empty($s['weekday_prices']) ? 'weekday' : 'single',
                ...collect(\App\Services\PriceBoards\PriceBoardV2Service::WEEKDAY_LABELS)
                    ->mapWithKeys(fn ($label, $day) => ['weekday_price_' . $day => $s['weekday_prices'][$day] ?? null])->all(),
                'default_checkin'     => $s['default_checkin'],
                'default_checkout'    => $s['default_checkout'],
                'deposit_min_nights'  => $s['deposit_min_nights'],
                'deposit_multi_night' => $s['deposit_multi_night'],
                'adjustment_type'     => $s['adjustment_type'],
                'adjustment_value'    => $s['adjustment_value'],
                'adjustment_timeslot_ids' => $s['adjustment_timeslot_ids'],
            ], array_values($spec['sheets'])),
        ];
    }

    // ════════════════════════════════════════════════════════════════════
    // Tuỳ chọn / điền nhanh
    // ════════════════════════════════════════════════════════════════════

    public static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /** Super_admin chưa chọn đối tác ở menu trên → phải chọn đối tác sở hữu bảng trong form. */
    public static function needsPartnerChoice(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->seesAllPartners();
    }

    /** Đối tác đang làm việc: tài khoản đối tác / super_admin đã chọn đối tác, hoặc đối tác chọn trong form. */
    public static function partnerId($livewire = null): ?string
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->seesAllPartners()) {
            return $user->partner_id ? (string) $user->partner_id : null;
        }

        $chosen = is_object($livewire) && isset($livewire->data) ? ($livewire->data['partner_id'] ?? null) : null;

        // Trang sửa: bảng đã có đối tác thì dùng luôn, kể cả khi ô chọn đối tác đang ẩn.
        if (blank($chosen) && is_object($livewire) && isset($livewire->record) && $livewire->record instanceof PriceBoard) {
            $chosen = $livewire->record->partner_id;
        }

        return filled($chosen) ? (string) $chosen : null;
    }

    /** @return array<int,string> */
    public static function branchOptions(?string $partnerId): array
    {
        if (! $partnerId) {
            return [];
        }

        $allowed = auth()->user()?->allowedCategoryIds() ?? [];

        return Category::withoutGlobalScopes()
            ->whereNull('parent_id')
            ->where('category_type', 'product')
            ->whereNull('deleted_at')
            ->where('partner_id', $partnerId)
            ->when(! empty($allowed), fn ($q) => $q->whereIn('id', $allowed))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->toArray();
    }

    /** @return array<string,string> */
    public static function roomOptions(?string $partnerId): array
    {
        if (! $partnerId) {
            return [];
        }

        return Product::query()->where('partner_id', $partnerId)->orderBy('name')->pluck('name', 'id')->toArray();
    }

    /** @return array<int,string> */
    public static function timeSlotOptions(?string $partnerId): array
    {
        $query = $partnerId ? TimeSlot::usableBy($partnerId) : TimeSlot::query()->whereNull('partner_id');

        return $query
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'date'))
            ->orderBy('start_time')
            ->get()
            ->mapWithKeys(fn (TimeSlot $t) => [$t->id => ($t->label ?: "{$t->start_time} - {$t->end_time}") . ($t->partner_id ? ' · riêng' : '')])
            ->all();
    }

    /** @return array<int,string> bảng giá mẫu dùng được (mẫu hệ thống + riêng; mẫu đã tách bản riêng thì ẩn) */
    public static function templateOptions(?string $partnerId): array
    {
        $forked = $partnerId
            ? PriceTemplate::where('partner_id', $partnerId)->whereNotNull('source_template_id')->pluck('source_template_id')
            : collect();

        return ($partnerId ? PriceTemplate::usableBy($partnerId) : PriceTemplate::query()->whereNull('partner_id'))
            ->where('is_active', true)
            ->whereNotIn('id', $forked)
            ->orderByRaw('partner_id IS NULL DESC')
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->mapWithKeys(fn ($t) => [$t->id => $t->name . ($t->partner_id ? ' (riêng)' : '')])
            ->all();
    }

    /**
     * Các dòng khung giờ + giá điền vào bộ giá: theo bảng giá mẫu hoặc random. null = thiếu lựa chọn.
     *
     * @return list<array{timeslot_id:int,price:int,over_night:bool}>|null
     */
    public static function slotRows(?string $partnerId, string $source, mixed $templateId, int $count): ?array
    {
        // Không có đối tác (bảng dựng sẵn của hệ thống): dùng khung / bảng mẫu của hệ thống.
        if ($source === 'random') {
            return array_map(
                fn (array $s) => ['timeslot_id' => $s['timeslot_id'], 'price' => $s['price'], 'over_night' => $s['over_night']],
                app(RoomSlotRandomizer::class)->pick($partnerId, $count)['slots']
            );
        }

        $template = ($partnerId ? PriceTemplate::usableBy($partnerId) : PriceTemplate::query()->whereNull('partner_id'))
            ->where('is_active', true)->with('slots')->find($templateId);

        if (! $template) {
            return null;
        }

        return $template->slots
            ->filter(fn ($s) => $s->price !== null)
            ->map(fn ($s) => ['timeslot_id' => (int) $s->timeslot_id, 'price' => (int) $s->price, 'over_night' => (bool) $s->over_night])
            ->values()
            ->all();
    }
}
