<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Forms;

use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Surcharge;

class InvoiceForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin hoá đơn')
                ->columns(2)
                ->schema([
                    // withoutGlobalScopes() BẮT BUỘC — Contract có global scope lọc theo "Toà nhà
                    // đang chọn" ở bộ lọc header (ScopedToActiveBuildingViaRoom). Thiếu dòng này,
                    // nếu bộ lọc header KHÁC toà nhà của hợp đồng đang gán cho hoá đơn này (VD đang
                    // sửa hoá đơn cũ trong khi header đã đổi sang xem toà khác), Filament không tra
                    // được record để lấy nhãn hiển thị, hiện ra ID thô ("3") thay vì "A-02 - Tô Xuân
                    // Nam" — bug đã gặp thật trên hoá đơn #1.
                    Select::make('contract_id')
                        ->label('Hợp đồng')
                        ->relationship(
                            'contract',
                            'id',
                            fn ($query) => $query->withoutGlobalScopes()->with(['room', 'tenant']),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->room?->code} - {$record->tenant?->fullname}")
                        ->searchable()
                        ->preload()
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set) {
                            static::recalcRoomPrice($get, $set);
                            static::fillUtilityDefaults($get, $set);
                            static::fillItemsFromContract($get, $set);
                        }),
                    DatePicker::make('month')
                        ->label('Tháng hoá đơn')
                        ->displayFormat('m/Y')
                        ->required()
                        ->live()
                        ->afterStateUpdated(function (Get $get, Set $set, $state) {
                            if (blank($state)) {
                                return;
                            }

                            $date = Carbon::parse($state);
                            $set('period_start', $date->copy()->startOfMonth()->toDateString());
                            $set('period_end', $date->copy()->endOfMonth()->toDateString());
                            static::recalcRoomPrice($get, $set);
                        }),
                    // KHÔNG cho sửa tay — status giờ TỰ TÍNH từ tổng các lần thanh toán ở mục
                    // "Thanh toán" bên dưới (xem InvoicePaymentObserver). Cho sửa tay ở đây sẽ tạo ra
                    // trạng thái giả (VD tự đánh "Đã thanh toán" mà chưa ghi lần trả nào), rồi bị
                    // ghi đè lại ngay lần tới có ai đó thêm/xoá 1 dòng thanh toán — gây khó hiểu.
                    // Hidden chỉ submit giá trị mặc định lúc TẠO MỚI (dehydrated theo operation),
                    // không đụng gì tới status đã tính khi Sửa.
                    Hidden::make('status')
                        ->default(Invoice::STATUS_UNPAID)
                        ->dehydrated(fn (string $operation) => $operation === 'create'),
                ]),

            // Kỳ tính tiền phòng thực tế — mặc định = trọn tháng ở trên, chỉ cần sửa lại khi khách
            // vào ở/trả phòng giữa tháng. Tiền phòng tự tính theo tỷ lệ số ngày (prorate), có thể
            // sửa tay sau nếu cần.
            Section::make('Kỳ tính tiền phòng')
                ->columns(2)
                ->schema([
                    DatePicker::make('period_start')
                        ->label('Từ ngày')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcRoomPrice($get, $set)),
                    DatePicker::make('period_end')
                        ->label('Đến ngày')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcRoomPrice($get, $set)),
                    TextInput::make('room_price')
                        ->label('Tiền phòng')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcTotal($get, $set))
                        ->helperText('Tự tính theo số ngày ở thực tế trong kỳ trên — sửa tay được nếu cần.')
                        ->columnSpanFull(),
                ]),

            Section::make('Chỉ số điện')
                ->columns(3)
                ->schema([
                    TextInput::make('electric_start')
                        ->label('Số đầu kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                    TextInput::make('electric_end')
                        ->label('Số cuối kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                    TextInput::make('electric_unit_price')
                        ->label('Đơn giá / số')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                    TextInput::make('electric_amount')
                        ->label('Thành tiền điện')
                        ->numeric()
                        ->prefix('đ')
                        ->readOnly()
                        ->columnSpanFull(),
                ]),

            Section::make('Chỉ số nước')
                ->columns(3)
                ->schema([
                    TextInput::make('water_start')
                        ->label('Số đầu kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                    TextInput::make('water_end')
                        ->label('Số cuối kỳ')
                        ->numeric()
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                    TextInput::make('water_unit_price')
                        ->label('Đơn giá / số')
                        ->numeric()
                        ->prefix('đ')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                    TextInput::make('water_amount')
                        ->label('Thành tiền nước')
                        ->numeric()
                        ->prefix('đ')
                        ->readOnly()
                        ->columnSpanFull(),
                ]),

            // Phụ thu chọn từ danh mục (Toà nhà > Phụ thu) — snapshot tên/số tiền vào từng dòng lúc
            // lập hoá đơn (đổi giá trong danh mục sau này không ảnh hưởng hoá đơn cũ). Vẫn sửa tay
            // được tên/số tiền từng dòng, hoặc thêm dòng phụ thu phát sinh không có trong danh mục.
            Section::make('Phụ thu')
                ->schema([
                    Repeater::make('items')
                        ->relationship('items')
                        ->label('')
                        // BẮT BUỘC set default([]) — thiếu dòng này, Repeater tự seed sẵn 1 dòng
                        // RỖNG ngay khi mở trang Tạo hoá đơn (dù không bấm "Thêm phụ thu"), khiến
                        // 'name'/'amount' ->required() của dòng rỗng đó chặn submit MỌI hoá đơn mới,
                        // kể cả hoá đơn không có phụ thu nào.
                        ->default([])
                        ->addActionLabel('Thêm phụ thu')
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcItemsTotal($get, $set))
                        ->collapsible()
                        ->columns(3)
                        ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'Phụ thu mới')
                        ->schema([
                            Select::make('surcharge_id')
                                ->label('Chọn từ danh mục')
                                ->options(function (Get $get) {
                                    $buildingId = Contract::find($get('../../contract_id'))?->room?->building_id;

                                    if (blank($buildingId)) {
                                        return [];
                                    }

                                    return Surcharge::where('building_id', $buildingId)
                                        ->where('is_active', true)
                                        ->pluck('name', 'id');
                                })
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                    $surcharge = Surcharge::find($state);

                                    if ($surcharge) {
                                        $set('name', $surcharge->name);
                                        $set('amount', $surcharge->amount);
                                    }

                                    static::recalcItemsTotalFromItem($get, $set);
                                }),
                            TextInput::make('name')
                                ->label('Tên phụ thu')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('amount')
                                ->label('Số tiền')
                                ->numeric()
                                ->minValue(0)
                                ->prefix('đ')
                                ->required()
                                ->live(onBlur: true)
                                ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcItemsTotalFromItem($get, $set)),
                        ]),
                ]),

            Section::make('Tổng cộng')
                ->columns(2)
                ->schema([
                    TextInput::make('service_amount')
                        ->label('Tổng phụ thu')
                        ->numeric()
                        ->prefix('đ')
                        ->readOnly()
                        ->helperText('Tự cộng từ danh sách phụ thu ở trên.'),
                    TextInput::make('total_amount')
                        ->label('Tổng tiền')
                        ->numeric()
                        ->minValue(0)
                        ->prefix('đ')
                        ->required()
                        ->helperText('Tự động cộng Tiền phòng + Điện + Nước + Dịch vụ khác — có thể sửa tay nếu cần.'),
                ]),

            // Ghi nhận thanh toán — CHỈ NHẬN ĐÚNG 1 LẦN duy nhất cho mỗi hoá đơn, số tiền phải bằng
            // đúng tổng tiền hoá đơn (yêu cầu 2026-09-07: bỏ hỗ trợ trả nhiều lần/trả từng phần —
            // xem Invoice::validateSinglePayment(), dùng chung với InvoicePaymentController phía
            // API). maxItems(1) chặn thêm dòng thứ 2 ngay trên giao diện (nút "Thêm" tự ẩn sau khi đã
            // có 1 dòng); rule() ở field amount chặn luôn cả trường hợp SỬA lại số tiền của dòng duy
            // nhất đó thành khác tổng hoá đơn.
            Section::make('Thanh toán')
                ->schema([
                    Placeholder::make('payment_summary')
                        ->label('')
                        ->content(fn (?Invoice $record) => $record
                            ? sprintf(
                                'Trạng thái: %s — Đã trả: %sđ / Tổng: %sđ — Còn lại: %sđ',
                                match ($record->status) {
                                    Invoice::STATUS_PAID    => 'Đã thanh toán',
                                    // STATUS_PARTIAL chỉ còn xuất hiện ở hoá đơn CŨ trước khi áp quy
                                    // tắc "chỉ 1 lần, đủ tiền" — không còn tạo mới được trạng thái
                                    // này nữa (xem validateSinglePayment()).
                                    Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần (dữ liệu cũ)',
                                    default                 => 'Chưa thanh toán',
                                },
                                number_format((float) $record->amount_paid, 0, ',', '.'),
                                number_format((float) $record->total_amount, 0, ',', '.'),
                                number_format($record->remainingAmount(), 0, ',', '.'),
                            )
                            : 'Lưu hoá đơn trước, sau đó mới ghi nhận thanh toán được.'),

                    Repeater::make('payments')
                        ->relationship('payments')
                        ->label('')
                        // default([]) — cùng lý do với Repeater 'items' ở trên, tránh seed sẵn 1 dòng
                        // rỗng dù Repeater này đang ẩn lúc tạo mới (->visible bên dưới).
                        ->default([])
                        ->addActionLabel('Ghi nhận thanh toán')
                        ->visible(fn (?Invoice $record) => $record !== null)
                        ->maxItems(1)
                        // status='pending' khi TẠO MỚI — nhân viên ghi nhận xong vẫn CHƯA tính là đã
                        // thanh toán (Invoice.amount_paid chỉ cộng khoản 'approved', xem
                        // InvoicePaymentObserver) cho tới khi "Chủ toà nhà" (quyền
                        // approve_invoice_payments) bấm duyệt ở nút "Duyệt thanh toán" trên header —
                        // xem EditInvoice.php.
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'created_by' => auth()->id(), 'status' => InvoicePayment::STATUS_PENDING])
                        // Chặn xoá khoản ĐÃ DUYỆT ngay trong Repeater — mặc định Filament vẫn hiện nút
                        // xoá của từng dòng bất kể trạng thái (chỉ 3 input amount/paid_at/payment_method
                        // bị disabled() ở trên khi đã duyệt, KHÔNG tự ẩn nút xoá). Xoá 1 khoản đã duyệt
                        // là xoá THẬT InvoicePayment (không SoftDeletes) kéo theo cascade xoá luôn dòng
                        // "Thu" tương ứng trong sổ Thu Chi — đúng lỗ hổng mà chặn xoá HOÁ ĐƠN
                        // (Invoice::hasApprovedPayment()) không phủ tới vì đó là chặn xoá CẢ hoá đơn,
                        // không phải chặn xoá riêng 1 dòng thanh toán bên trong. Ghi đè thẳng
                        // action() (thay vì cố dùng deletable(fn (Get $get)) — closure đó chạy ở scope
                        // của CHÍNH Repeater, không phải scope của từng item, nên không đọc được
                        // 'status' của riêng dòng đang xoá).
                        ->deleteAction(fn (Action $action) => $action->action(function (array $arguments, Repeater $component) {
                            $items = $component->getState();
                            $item  = $items[$arguments['item']] ?? null;

                            if (($item['status'] ?? null) === InvoicePayment::STATUS_APPROVED) {
                                Notification::make()
                                    ->title('Không thể xoá')
                                    ->body('Khoản thanh toán này đã được duyệt — không thể xoá để tránh mất dữ liệu sổ Thu Chi.')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            unset($items[$arguments['item']]);
                            $component->state($items);
                            $component->callAfterStateUpdated();
                        }))
                        ->collapsible()
                        ->columns(3)
                        ->itemLabel(fn (array $state): ?string => isset($state['amount'])
                            ? number_format((float) $state['amount'], 0, ',', '.') . 'đ — ' . ($state['paid_at'] ?? '')
                            . (($state['status'] ?? null) === InvoicePayment::STATUS_APPROVED ? ' — Đã duyệt ✓' : ' — Chờ Chủ toà nhà duyệt')
                            : 'Lần thanh toán mới')
                        ->schema([
                            // Đã duyệt rồi thì KHOÁ không cho sửa nữa — tránh nhân viên đổi số tiền/
                            // ngày sau khi Chủ toà nhà đã xác nhận, làm sai lệch dữ liệu đã duyệt.
                            TextInput::make('amount')
                                ->label('Số tiền')
                                ->numeric()
                                ->prefix('đ')
                                ->required()
                                ->disabled(fn (Get $get) => $get('status') === InvoicePayment::STATUS_APPROVED)
                                ->helperText('Phải đúng bằng Tổng tiền hoá đơn — hệ thống chỉ nhận thanh toán 1 lần duy nhất.')
                                ->rule(fn (Get $get) => function (string $attribute, $value, \Closure $fail) use ($get) {
                                    $total = (float) $get('../../total_amount');

                                    if (abs((float) $value - $total) > 0.01) {
                                        $fail('Số tiền phải đúng bằng tổng tiền hoá đơn (' . number_format($total, 0, ',', '.') . 'đ).');
                                    }
                                }),
                            DatePicker::make('paid_at')
                                ->label('Ngày thanh toán')
                                ->native(false)
                                ->default(now())
                                ->required()
                                ->disabled(fn (Get $get) => $get('status') === InvoicePayment::STATUS_APPROVED),
                            Select::make('payment_method')
                                ->label('Hình thức')
                                ->disabled(fn (Get $get) => $get('status') === InvoicePayment::STATUS_APPROVED)
                                ->options([
                                    InvoicePayment::METHOD_CASH     => 'Tiền mặt',
                                    InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
                                    InvoicePayment::METHOD_OTHER    => 'Khác',
                                ]),
                            Textarea::make('note')
                                ->label('Ghi chú')
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    // Tiền phòng = giá thuê/tháng của hợp đồng, tính theo tỷ lệ số ngày trong kỳ / số ngày của tháng
    // đó — khách ở trọn tháng thì period_start/period_end mặc định trùng đầu/cuối tháng nên ra đúng
    // nguyên giá thuê, không cần làm gì thêm.
    private static function recalcRoomPrice(Get $get, Set $set): void
    {
        $contractId  = $get('contract_id');
        $periodStart = $get('period_start');
        $periodEnd   = $get('period_end');

        if (blank($contractId) || blank($periodStart) || blank($periodEnd)) {
            return;
        }

        $contract = Contract::find($contractId);

        if (! $contract) {
            return;
        }

        $start = Carbon::parse($periodStart);
        $end   = Carbon::parse($periodEnd);

        if ($end->lt($start)) {
            return;
        }

        $daysInPeriod = $start->diffInDays($end) + 1;
        $daysInMonth  = $start->daysInMonth;

        $roomPrice = $daysInPeriod >= $daysInMonth
            ? (float) $contract->monthly_price
            : round(((float) $contract->monthly_price / $daysInMonth) * $daysInPeriod, 0);

        $set('room_price', $roomPrice);
        static::recalcTotal($get, $set);
    }

    private static function recalcElectric(Get $get, Set $set): void
    {
        $amount = (max(0, (float) $get('electric_end') - (float) $get('electric_start'))) * (float) $get('electric_unit_price');
        $set('electric_amount', round($amount, 0));
        static::recalcTotal($get, $set);
    }

    private static function recalcWater(Get $get, Set $set): void
    {
        $amount = (max(0, (float) $get('water_end') - (float) $get('water_start'))) * (float) $get('water_unit_price');
        $set('water_amount', round($amount, 0));
        static::recalcTotal($get, $set);
    }

    private static function recalcTotal(Get $get, Set $set): void
    {
        $total = (float) $get('room_price')
            + (float) $get('electric_amount')
            + (float) $get('water_amount')
            + (float) $get('service_amount');

        $set('total_amount', round($total, 0));
    }

    // Đơn giá điện/nước — ưu tiên đọc từ chính Hợp đồng (đã ghi rõ trong hợp đồng, có thể khác giá
    // chung nếu thương lượng riêng), rơi về đơn giá mặc định của Toà nhà nếu hợp đồng chưa có giá
    // riêng. Chỉ điền khi field đang TRỐNG, không ghi đè nếu nhân viên đã tự sửa (VD tháng này EVN
    // đổi giá, hoặc hoá đơn đã lập trước đó đang Sửa lại).
    private static function fillUtilityDefaults(Get $get, Set $set): void
    {
        $contract = Contract::find($get('contract_id'));

        if (! $contract) {
            return;
        }

        $electricPrice = $contract->electric_unit_price ?: $contract->room?->building?->electric_unit_price;
        $waterPrice    = $contract->water_unit_price ?: $contract->room?->building?->water_unit_price;

        if (blank($get('electric_unit_price')) && filled($electricPrice)) {
            $set('electric_unit_price', $electricPrice);
        }

        if (blank($get('water_unit_price')) && filled($waterPrice)) {
            $set('water_unit_price', $waterPrice);
        }

        static::recalcElectric($get, $set);
        static::recalcWater($get, $set);
    }

    // Tự điền danh sách phụ thu ĐỊNH KỲ đã chọn sẵn trên Hợp đồng (tab "Các khoản phí hàng tháng")
    // vào hoá đơn MỚI — chỉ khi Repeater 'items' đang TRỐNG (tạo hoá đơn lần đầu cho hợp đồng này),
    // không tự động ghi đè nếu đang Sửa hoá đơn đã có sẵn dòng (kể cả do nhân viên tự thêm tay).
    private static function fillItemsFromContract(Get $get, Set $set): void
    {
        if (filled($get('items'))) {
            return;
        }

        $contract = Contract::find($get('contract_id'));

        if (! $contract) {
            return;
        }

        $items = $contract->surcharges()->get()
            ->map(fn ($surcharge) => [
                'surcharge_id' => $surcharge->id,
                'name'         => $surcharge->name,
                'amount'       => $surcharge->amount,
            ])
            ->all();

        if (empty($items)) {
            return;
        }

        $set('items', $items);
        static::recalcItemsTotal($get, $set);
    }

    // Gọi từ Repeater 'items' (cấp gốc — thêm/xoá dòng) — $get/$set ở đây đã ở đúng scope gốc.
    private static function recalcItemsTotal(Get $get, Set $set): void
    {
        $serviceAmount = collect($get('items') ?? [])->sum(fn ($item) => (float) ($item['amount'] ?? 0));

        $set('service_amount', round($serviceAmount, 0));
        static::recalcTotal($get, $set);
    }

    // Gọi từ BÊN TRONG 1 item của Repeater 'items' (sửa surcharge_id/amount của riêng dòng đó) —
    // $get/$set ở đây đang ở scope của ITEM, phải dùng path tương đối: '../' = nguyên mảng items
    // (1 cấp lên khỏi item), '../../{field}' = field ở gốc form (2 cấp lên khỏi item).
    private static function recalcItemsTotalFromItem(Get $get, Set $set): void
    {
        $serviceAmount = collect($get('../') ?? [])->sum(fn ($item) => (float) ($item['amount'] ?? 0));
        $serviceAmount = round($serviceAmount, 0);

        $set('../../service_amount', $serviceAmount);

        $total = (float) $get('../../room_price')
            + (float) $get('../../electric_amount')
            + (float) $get('../../water_amount')
            + $serviceAmount;

        $set('../../total_amount', round($total, 0));
    }
}
