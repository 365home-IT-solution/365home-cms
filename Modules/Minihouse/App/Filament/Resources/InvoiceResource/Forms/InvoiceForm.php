<?php

namespace Modules\Minihouse\App\Filament\Resources\InvoiceResource\Forms;

use Filament\Forms\Components\Actions as FormActions;
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
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Modules\Metering\App\Models\MeteringReading;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Surcharge;

class InvoiceForm
{
    public static function form(Form $form): Form
    {
        // ->columns(1) BẮT BUỘC khai báo rõ ở cấp cao nhất — không khai báo thì Filament render
        // <form class="fi-form grid gap-y-6"> KHÔNG có grid-cols nào (grid ẩn, cột tự co theo nội
        // dung/"auto" thay vì "1fr"), khiến cả cụm Section/Group bị co hẹp lại và dồn về bên trái,
        // để trống khoảng lớn bên phải — dù bên trong mỗi Group vẫn chia cột đúng tỷ lệ với nhau.
        // Khai báo tường minh 1 cột buộc Filament dùng "minmax(0, 1fr)" (giãn hết chiều rộng thật).
        return $form->columns(1)->schema([
            // Gộp "Thông tin hoá đơn" + "Kỳ tính tiền phòng" (trước đây 2 Section riêng) vào 1 —
            // cùng là dữ liệu gốc để tính tiền phòng, tách rời chỉ làm trang dài thêm không cần thiết.
            Section::make('Thông tin hoá đơn')
                ->columns(3)
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
                            static::fillUtilityDefaults($get, $set);
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

                    // Kỳ tính tiền phòng thực tế — mặc định = trọn tháng ở trên, chỉ cần sửa lại khi
                    // khách vào ở/trả phòng giữa tháng. Tiền phòng tự tính theo tỷ lệ số ngày
                    // (prorate), có thể sửa tay sau nếu cần.
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
                        ->helperText('Tự tính theo số ngày thực tế trong kỳ — sửa tay được nếu cần.'),
                ]),

            // Điện + Nước — 2 card riêng CHUNG 1 hàng (kích thước tương đồng, đặt cạnh nhau dễ so
            // sánh); Phụ thu xuống hàng riêng NGAY BÊN DƯỚI vì nó là danh sách (Repeater) dài ngắn
            // tuỳ hoá đơn, đặt chung hàng với Điện/Nước sẽ làm 2 card kia cao thấp lệch nhau.
            Group::make([
                // ->columnSpan(1) BẮT BUỘC — Section MẶC ĐỊNH tự chiếm columnSpanFull() (span hết cả
                // hàng của Group cha) bất kể Group cha khai báo mấy cột, đây mới là lý do THẬT SỰ 2
                // card cứ xếp dọc dù Group đã ->columns(['default' => 2]) đúng — xác nhận qua HTML
                // thật: style="--col-span-default: 1 / -1" (span hết hàng) trên chính Section, không
                // liên quan gì đến breakpoint responsive như đã tưởng lúc đầu.
                Section::make('Điện')
                    ->columnSpan(1)
                    ->columns(2)
                    ->schema([
                        TextInput::make('electric_start')
                            ->label('Đầu kỳ')
                            ->numeric()
                            ->live(onBlur: true)
                            ->readOnly(fn (Get $get) => static::hasMeteringReading($get))
                            ->helperText(fn (Get $get) => static::hasMeteringReading($get) ? 'Lấy từ Số điện nước.' : null)
                            ->extraInputAttributes(fn (Get $get) => static::lockedFieldAttributes($get))
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                        TextInput::make('electric_end')
                            ->label('Cuối kỳ')
                            ->numeric()
                            ->live(onBlur: true)
                            ->readOnly(fn (Get $get) => static::hasMeteringReading($get))
                            ->helperText(fn (Get $get) => static::hasMeteringReading($get) ? 'Lấy từ Số điện nước.' : null)
                            ->extraInputAttributes(fn (Get $get) => static::lockedFieldAttributes($get))
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                        // Ẩn đơn giá — nhân viên không cần thấy/sửa ở đây (giá vẫn lấy đúng từ Hợp
                        // đồng/Toà nhà như cũ, xem fillUtilityDefaults()), chỉ làm rối form. Vẫn giữ
                        // ->hidden() (không phải bỏ hẳn field) để giá trị tiếp tục được gửi lên lúc
                        // lưu và vẫn dùng được trong các closure $get('electric_unit_price').
                        TextInput::make('electric_unit_price')
                            ->label('Đơn giá / số')
                            ->numeric()
                            ->prefix('đ')
                            ->live(onBlur: true)
                            ->hidden()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcElectric($get, $set)),
                        TextInput::make('electric_amount')
                            ->label('Thành tiền')
                            ->numeric()
                            ->prefix('đ')
                            ->readOnly()
                            ->extraInputAttributes(['style' => 'background-color: rgba(120,120,128,0.12) !important;']),
                    ]),
                Section::make('Nước')
                    ->columnSpan(1)
                    ->columns(2)
                    ->schema([
                        TextInput::make('water_start')
                            ->label('Đầu kỳ')
                            ->numeric()
                            ->live(onBlur: true)
                            ->readOnly(fn (Get $get) => static::hasMeteringReading($get))
                            ->helperText(fn (Get $get) => static::hasMeteringReading($get) ? 'Lấy từ Số điện nước.' : null)
                            ->extraInputAttributes(fn (Get $get) => static::lockedFieldAttributes($get))
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                        TextInput::make('water_end')
                            ->label('Cuối kỳ')
                            ->numeric()
                            ->live(onBlur: true)
                            ->readOnly(fn (Get $get) => static::hasMeteringReading($get))
                            ->helperText(fn (Get $get) => static::hasMeteringReading($get) ? 'Lấy từ Số điện nước.' : null)
                            ->extraInputAttributes(fn (Get $get) => static::lockedFieldAttributes($get))
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                        TextInput::make('water_unit_price')
                            ->label('Đơn giá / số')
                            ->numeric()
                            ->prefix('đ')
                            ->live(onBlur: true)
                            ->hidden()
                            ->afterStateUpdated(fn (Get $get, Set $set) => static::recalcWater($get, $set)),
                        TextInput::make('water_amount')
                            ->label('Thành tiền')
                            ->numeric()
                            ->prefix('đ')
                            ->readOnly()
                            ->extraInputAttributes(['style' => 'background-color: rgba(120,120,128,0.12) !important;']),
                    ]),
            // ->columns(['default' => 2]) — CHỈ 1 key 'default' — ép LUÔN 2 cột ở MỌI kích thước màn
            // hình. Truyền số nguyên trần ->columns(2) KHÔNG có tác dụng này — Filament tự hiểu số
            // nguyên là "2 cột chỉ áp dụng từ breakpoint 'lg' (1024px) trở lên, mặc định 1 cột dưới
            // đó", nên 2 card vẫn bị rớt xuống xếp dọc nếu vùng nội dung (sau khi trừ sidebar) hẹp hơn
            // 1024px dù màn hình vật lý rất rộng — lỗi thật đã gặp khi kiểm tra HTML render ra
            // (--cols-default: repeat(1,...); --cols-lg: repeat(2,...)).
            ])->columns(['default' => 2]),

            // Card riêng cho việc đồng bộ Số điện nước — bọc nút trong 1 Section như mọi khối khác
            // trên trang (Điện, Nước, Phụ thu...) thay vì để trơ 1 hàng nút không khung, nhìn đồng bộ
            // với phần còn lại thay vì lạc quẻ/xấu. Chỉ hiện khi hoá đơn đang lấy số từ 1 log Số điện
            // nước thật.
            Section::make()
                ->visible(fn (Get $get) => static::hasMeteringReading($get))
                ->schema([
                    FormActions::make([
                        Action::make('editMeteringReading')
                            ->label('Cập nhật Số điện nước')
                            ->icon('heroicon-o-pencil-square')
                            ->color('primary')
                            ->size('lg')
                            ->modalIcon('heroicon-o-bolt')
                            ->modalHeading('Cập nhật Số điện nước')
                            ->modalDescription(fn (Get $get) => static::meteringReadingModalDescription($get))
                            ->modalSubmitActionLabel('Lưu')
                            ->modalWidth('md')
                            // fillForm CHỈ tính lúc mở modal — dùng luôn dữ liệu THẬT từ log (không
                            // phải state hoá đơn) để chắc chắn khớp module Metering, phòng trường hợp
                            // hiếm hoi hoá đơn chưa đồng bộ kịp.
                            ->fillForm(function (Get $get) {
                                $reading = static::meteringReadingFor($get);

                                return [
                                    'electric_start' => $reading?->electric_start,
                                    'electric_end'   => $reading?->electric_end,
                                    'water_start'    => $reading?->water_start,
                                    'water_end'      => $reading?->water_end,
                                ];
                            })
                            // Bố cục giống hệt form thật của module Metering (Đầu kỳ khoá + nền xám,
                            // Cuối kỳ sửa được) — nhân viên thấy quen mắt, không phải học lại giao
                            // diện khác chỉ vì đang thao tác từ hoá đơn.
                            ->form([
                                Section::make('Điện')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('electric_start')
                                            ->label('Đầu kỳ')
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->extraInputAttributes(['style' => 'background-color: rgba(120,120,128,0.12) !important;']),
                                        TextInput::make('electric_end')
                                            ->label('Cuối kỳ')
                                            ->numeric()
                                            ->required(),
                                    ]),
                                Section::make('Nước')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('water_start')
                                            ->label('Đầu kỳ')
                                            ->numeric()
                                            ->disabled()
                                            ->dehydrated(false)
                                            ->extraInputAttributes(['style' => 'background-color: rgba(120,120,128,0.12) !important;']),
                                        TextInput::make('water_end')
                                            ->label('Cuối kỳ')
                                            ->numeric()
                                            ->required(),
                                    ]),
                            ])
                            ->action(function (array $data, Get $get, Set $set) {
                                $reading = static::meteringReadingFor($get);

                                if (! $reading) {
                                    return;
                                }

                                $reading->update([
                                    'electric_end' => $data['electric_end'],
                                    'water_end'    => $data['water_end'],
                                ]);

                                // Đẩy ngay số mới vào hoá đơn đang mở (không cần tải lại trang) —
                                // đúng số vừa sửa ở log, tính lại luôn Thành tiền/Tổng tiền theo số
                                // mới.
                                $set('electric_end', $reading->electric_end);
                                $set('water_end', $reading->water_end);
                                static::recalcElectric($get, $set);
                                static::recalcWater($get, $set);

                                Notification::make()
                                    ->title('Đã cập nhật Số điện nước')
                                    ->success()
                                    ->send();
                            }),
                    ]),
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

            // Ghi nhận thanh toán — CHỈ NHẬN ĐÚNG 1 LẦN duy nhất cho mỗi hoá đơn, số tiền LUÔN đúng
            // bằng tổng tiền hoá đơn (Invoice::validateSinglePayment() — không hỗ trợ trả từng phần).
            // Vì số tiền không bao giờ khác tổng hoá đơn, đơn giản hoá thành 1 NÚT DUY NHẤT "Đánh dấu
            // đã thanh toán" thay vì cả 1 form Repeater — nhân viên chỉ cần xác nhận Ngày + Hình thức,
            // số tiền tự điền, không phải gõ tay. Tạo xong vẫn ở trạng thái "pending" (chờ "Chủ toà
            // nhà" bấm "Duyệt thanh toán" ở header — xem EditInvoice::getHeaderActions()), giữ nguyên
            // quy trình duyệt cũ, chỉ đơn giản hoá bước NHẬP của nhân viên.
            // Thu gọn sẵn lúc TẠO MỚI (chưa thể ghi thanh toán khi chưa lưu hoá đơn) — mở sẵn ngay khi
            // Sửa 1 hoá đơn đã có (trường hợp cần xem/ghi nhận thanh toán, đúng lúc cần dùng nhất).
            Section::make('Thanh toán')
                ->collapsible()
                ->collapsed(fn (?Invoice $record) => $record === null)
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

                    // Đã có 1 lần thanh toán (pending hoặc approved) thì hiện lại đúng thông tin đó,
                    // không cho sửa — khớp đúng nguyên tắc "chỉ 1 lần duy nhất" đã áp dụng từ trước.
                    Placeholder::make('payment_detail')
                        ->label('')
                        ->visible(fn (?Invoice $record) => $record?->payments()->exists())
                        ->content(function (?Invoice $record) {
                            $payment = $record?->payments()->latest()->first();

                            if (! $payment) {
                                return '';
                            }

                            $methodLabel = match ($payment->payment_method) {
                                InvoicePayment::METHOD_CASH     => 'Tiền mặt',
                                InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
                                default                          => 'Khác',
                            };

                            return sprintf(
                                'Đã ghi nhận: %sđ — %s — %s — %s',
                                number_format((float) $payment->amount, 0, ',', '.'),
                                $payment->paid_at?->format('d/m/Y'),
                                $methodLabel,
                                $payment->isApproved() ? 'Đã duyệt ✓' : 'Chờ Chủ toà nhà duyệt',
                            );
                        }),

                    FormActions::make([
                        Action::make('markPaid')
                            ->label('Đánh dấu đã thanh toán')
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->visible(fn (?Invoice $record) => $record !== null && ! $record->payments()->exists())
                            ->modalHeading('Xác nhận đã thanh toán')
                            ->modalSubmitActionLabel('Xác nhận')
                            ->form([
                                DatePicker::make('paid_at')
                                    ->label('Ngày thanh toán')
                                    ->native(false)
                                    ->default(now())
                                    ->required(),
                                Select::make('payment_method')
                                    ->label('Hình thức')
                                    ->required()
                                    ->options([
                                        InvoicePayment::METHOD_CASH     => 'Tiền mặt',
                                        InvoicePayment::METHOD_TRANSFER => 'Chuyển khoản',
                                        InvoicePayment::METHOD_OTHER    => 'Khác',
                                    ]),
                            ])
                            ->action(function (array $data, ?Invoice $record, \Livewire\Component $livewire) {
                                if (! $record) {
                                    return;
                                }

                                $record->payments()->create([
                                    'amount'         => $record->total_amount,
                                    'paid_at'        => $data['paid_at'],
                                    'payment_method' => $data['payment_method'],
                                    'status'         => InvoicePayment::STATUS_PENDING,
                                    'created_by'     => auth()->id(),
                                ]);

                                $record->refresh();
                                $livewire->refreshFormData(['status', 'amount_paid']);

                                Notification::make()
                                    ->title('Đã ghi nhận thanh toán — chờ Chủ toà nhà duyệt')
                                    ->success()
                                    ->send();
                            }),

                        // Cho phép huỷ nếu ghi nhầm — CHỈ khi còn "pending" (chưa duyệt). Khoản ĐÃ
                        // DUYỆT không thể xoá qua đây (xoá thật InvoicePayment sẽ kéo theo cascade
                        // xoá dòng "Thu" trong sổ Thu Chi — chỉ nên xoá cả hoá đơn hoặc xử lý thủ công
                        // nếu thực sự cần, không cho thao tác nhầm từ nút bấm thường).
                        Action::make('cancelPendingPayment')
                            ->label('Huỷ (ghi nhầm)')
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->visible(fn (?Invoice $record) => $record?->payments()->where('status', InvoicePayment::STATUS_PENDING)->exists())
                            ->requiresConfirmation()
                            ->modalDescription('Xoá khoản thanh toán vừa ghi nhận (chưa duyệt) để nhập lại?')
                            ->action(function (?Invoice $record, \Livewire\Component $livewire) {
                                if (! $record) {
                                    return;
                                }

                                $record->payments()->where('status', InvoicePayment::STATUS_PENDING)->delete();
                                $record->refresh();
                                $livewire->refreshFormData(['status', 'amount_paid']);

                                Notification::make()
                                    ->title('Đã huỷ khoản thanh toán')
                                    ->success()
                                    ->send();
                            }),
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

        // Module Metering (tách riêng, chỉ quản lý CHỈ SỐ) có thể đã có log chỉ số điện/nước của
        // đúng phòng + đúng tháng — ưu tiên lấy thẳng từ log đó (khoá field lại, xem
        // hasMeteringReading()) thay vì để nhân viên gõ tay. Không có log tháng này thì giữ hành vi
        // cũ (nhập tay).
        $reading = static::meteringReadingFor($get);

        if ($reading) {
            $set('electric_start', $reading->electric_start);
            $set('electric_end', $reading->electric_end);
            $set('water_start', $reading->water_start);
            $set('water_end', $reading->water_end);
        }

        static::recalcElectric($get, $set);
        static::recalcWater($get, $set);
    }

    // Log chỉ số của đúng phòng (theo hợp đồng đang chọn) + đúng tháng hoá đơn — dùng để tự điền và
    // để khoá (readOnly) các field điện/nước trên form khi module Metering đã có log tháng đó.
    private static function meteringReadingFor(Get $get): ?MeteringReading
    {
        $month = $get('month');

        if (blank($get('contract_id')) || blank($month)) {
            return null;
        }

        $contract = Contract::find($get('contract_id'));
        $roomId   = $contract?->room_id;

        if (blank($roomId)) {
            return null;
        }

        return MeteringReading::forRoomAndMonth((int) $roomId, Carbon::parse($month));
    }

    private static function hasMeteringReading(Get $get): bool
    {
        return static::meteringReadingFor($get) !== null;
    }

    // "Phòng A-06 — Tháng 10/2026" hiện ngay dưới tiêu đề modal — nhân viên biết chắc đang sửa đúng
    // log nào trước khi bấm Lưu, tránh nhầm giữa nhiều hoá đơn đang mở.
    private static function meteringReadingModalDescription(Get $get): string
    {
        $reading = static::meteringReadingFor($get);

        if (! $reading) {
            return '';
        }

        return 'Phòng ' . ($reading->room?->code ?? '—') . ' — Tháng ' . $reading->month->format('m/Y');
    }

    // Filament ->readOnly() KHÔNG tự đổi giao diện (không tô nền xám như ->disabled()) — ô readOnly
    // nhìn giống hệt ô nhập bình thường (nền trắng, viền thường), dễ khiến nhân viên tưởng vẫn gõ sửa
    // được dù thực chất gõ vào không ăn (đã xảy ra thật, người dùng báo nhầm là lỗi "vẫn cho sửa").
    // Tô nền xám nhạt khi đang khoá để nhìn là biết ngay, không cần thử gõ mới biết. Dùng "style" nội
    // tuyến + !important thay vì "class" — input đã có sẵn class "bg-white/0" từ Filament, cùng mức
    // độ ưu tiên CSS nên thứ tự class trong HTML KHÔNG quyết định class nào thắng (phụ thuộc thứ tự
    // biên dịch của Tailwind), có thể khiến nền xám không hiện ra dù class đã gắn đúng.
    /** @return array<string, string> */
    private static function lockedFieldAttributes(Get $get): array
    {
        return static::hasMeteringReading($get) ? ['style' => 'background-color: rgba(120,120,128,0.12) !important;'] : [];
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
