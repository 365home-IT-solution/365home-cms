<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Forms;

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
                    Select::make('contract_id')
                        ->label('Hợp đồng')
                        ->relationship(
                            'contract',
                            'id',
                            fn ($query) => $query->with(['room', 'tenant']),
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
                        ->prefix('đ')
                        ->required()
                        ->helperText('Tự động cộng Tiền phòng + Điện + Nước + Dịch vụ khác — có thể sửa tay nếu cần.'),
                ]),

            // Ghi nhận từng LẦN thanh toán — hỗ trợ khách trả nhiều lần (trả trước 1 phần, phần còn
            // lại sau). Trạng thái/Đã trả/Còn lại ở trên TỰ TÍNH lại ngay khi thêm/sửa/xoá 1 dòng ở
            // đây (InvoicePaymentObserver), không cần bấm gì thêm.
            Section::make('Thanh toán')
                ->schema([
                    Placeholder::make('payment_summary')
                        ->label('')
                        ->content(fn (?Invoice $record) => $record
                            ? sprintf(
                                'Trạng thái: %s — Đã trả: %sđ / Tổng: %sđ — Còn lại: %sđ',
                                match ($record->status) {
                                    Invoice::STATUS_PAID    => 'Đã thanh toán',
                                    Invoice::STATUS_PARTIAL => 'Thanh toán 1 phần',
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
                        ->addActionLabel('Ghi nhận thanh toán')
                        ->visible(fn (?Invoice $record) => $record !== null)
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => [...$data, 'created_by' => auth()->id()])
                        ->collapsible()
                        ->columns(3)
                        ->itemLabel(fn (array $state): ?string => isset($state['amount'])
                            ? number_format((float) $state['amount'], 0, ',', '.') . 'đ — ' . ($state['paid_at'] ?? '')
                            : 'Lần thanh toán mới')
                        ->schema([
                            TextInput::make('amount')
                                ->label('Số tiền')
                                ->numeric()
                                ->prefix('đ')
                                ->required(),
                            DatePicker::make('paid_at')
                                ->label('Ngày thanh toán')
                                ->native(false)
                                ->default(now())
                                ->required(),
                            Select::make('payment_method')
                                ->label('Hình thức')
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
            : round(((float) $contract->monthly_price / $daysInMonth) * $daysInPeriod, 2);

        $set('room_price', $roomPrice);
        static::recalcTotal($get, $set);
    }

    private static function recalcElectric(Get $get, Set $set): void
    {
        $amount = (max(0, (float) $get('electric_end') - (float) $get('electric_start'))) * (float) $get('electric_unit_price');
        $set('electric_amount', round($amount, 2));
        static::recalcTotal($get, $set);
    }

    private static function recalcWater(Get $get, Set $set): void
    {
        $amount = (max(0, (float) $get('water_end') - (float) $get('water_start'))) * (float) $get('water_unit_price');
        $set('water_amount', round($amount, 2));
        static::recalcTotal($get, $set);
    }

    private static function recalcTotal(Get $get, Set $set): void
    {
        $total = (float) $get('room_price')
            + (float) $get('electric_amount')
            + (float) $get('water_amount')
            + (float) $get('service_amount');

        $set('total_amount', round($total, 2));
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

        $set('service_amount', round($serviceAmount, 2));
        static::recalcTotal($get, $set);
    }

    // Gọi từ BÊN TRONG 1 item của Repeater 'items' (sửa surcharge_id/amount của riêng dòng đó) —
    // $get/$set ở đây đang ở scope của ITEM, phải dùng path tương đối: '../' = nguyên mảng items
    // (1 cấp lên khỏi item), '../../{field}' = field ở gốc form (2 cấp lên khỏi item).
    private static function recalcItemsTotalFromItem(Get $get, Set $set): void
    {
        $serviceAmount = collect($get('../') ?? [])->sum(fn ($item) => (float) ($item['amount'] ?? 0));
        $serviceAmount = round($serviceAmount, 2);

        $set('../../service_amount', $serviceAmount);

        $total = (float) $get('../../room_price')
            + (float) $get('../../electric_amount')
            + (float) $get('../../water_amount')
            + $serviceAmount;

        $set('../../total_amount', round($total, 2));
    }
}
