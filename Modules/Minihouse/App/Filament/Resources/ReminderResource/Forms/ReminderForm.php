<?php

namespace Modules\Minihouse\App\Filament\Resources\ReminderResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\Reminder;
use Modules\Minihouse\App\Support\MinihousePermissions;

class ReminderForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Thông tin nhắc việc')
                ->columns(2)
                ->schema([
                    TextInput::make('title')
                        ->label('Tiêu đề')
                        ->required()
                        ->maxLength(255),
                    Select::make('type')
                        ->label('Loại')
                        ->options([
                            Reminder::TYPE_PAYMENT     => 'Nhắc đóng tiền',
                            Reminder::TYPE_CONTRACT    => 'Nhắc hết hạn hợp đồng',
                            Reminder::TYPE_MAINTENANCE => 'Nhắc bảo trì',
                            Reminder::TYPE_OTHER       => 'Khác',
                        ])
                        ->default(Reminder::TYPE_OTHER)
                        ->live()
                        ->required(),
                    DatePicker::make('remind_date')
                        ->label('Ngày nhắc')
                        ->required(),
                    // Chỉ áp dụng cho "Nhắc bảo trì" (kiểm tra PCCC, bảo trì thang máy...) — các loại
                    // còn lại không cần lặp (mỗi hợp đồng chỉ hết hạn 1 lần, mỗi hoá đơn chỉ nhắc 1
                    // lần). Đánh dấu "Đã xử lý" cho nhắc việc này thì ReminderObserver tự sinh 1 nhắc
                    // việc MỚI, ngày nhắc = ngày nhắc cũ + số ngày ở đây — không cần tạo tay lại mỗi
                    // chu kỳ như trước.
                    Select::make('repeat_interval_days')
                        ->label('Lặp lại')
                        ->visible(fn (Get $get) => $get('type') === Reminder::TYPE_MAINTENANCE)
                        ->options([
                            7   => 'Mỗi tuần',
                            30  => 'Mỗi tháng',
                            90  => 'Mỗi quý (3 tháng)',
                            180 => 'Mỗi 6 tháng',
                            365 => 'Mỗi năm',
                        ])
                        ->placeholder('Không lặp lại (chỉ nhắc 1 lần)')
                        ->helperText('Khi đánh dấu "Đã xử lý", hệ thống tự tạo nhắc việc kế tiếp theo đúng chu kỳ này.'),
                    // Giao đúng 1 nhân viên chịu trách nhiệm xử lý — chủ yếu dùng cho "Nhắc bảo trì"
                    // (VD giao thợ điện phụ trách sửa máy lạnh phòng A02), tránh đùn đẩy khi nhiều
                    // nhân viên cùng thấy 1 nhắc việc chung chung không rõ ai làm.
                    Select::make('assigned_to')
                        ->label('Giao cho nhân viên')
                        // whereNotNull('fullname') — Filament ép nhãn option phải là string, tài
                        // khoản nào chưa điền "Họ tên" (User.fullname NULL) sẽ làm nhãn null và làm
                        // vỡ CẢ TRANG (lỗi 500 tại Select::isOptionDisabled(), xác nhận thật khi có 1
                        // tài khoản Nhân viên MiniHouse bỏ trống Họ tên) — loại các tài khoản đó khỏi
                        // danh sách chọn thay vì để vỡ trang; vào Người dùng > sửa tài khoản, điền Họ
                        // tên thì tài khoản đó sẽ hiện lại được ở đây.
                        ->relationship(
                            'assignee',
                            'fullname',
                            fn ($query) => MinihousePermissions::scopeToMinihouseUsers($query)->whereNotNull('fullname'),
                        )
                        ->searchable()
                        ->preload()
                        ->placeholder('Chưa giao cho ai cụ thể'),
                    Select::make('room_id')
                        ->label('Phòng liên quan')
                        // withoutGlobalScopes() — tránh hiện ID thô khi bộ lọc header đang khác toà
                        // với phòng đã gán (cùng lỗi đã gặp ở InvoiceForm.contract_id).
                        ->relationship('room', 'code', fn ($query) => $query->withoutGlobalScopes())
                        ->searchable()
                        ->preload(),
                    Select::make('contract_id')
                        ->label('Hợp đồng liên quan')
                        ->relationship(
                            'contract',
                            'id',
                            fn ($query) => $query->withoutGlobalScopes()->with(['room', 'tenant']),
                        )
                        ->getOptionLabelFromRecordUsing(fn ($record) => "{$record->room?->code} - {$record->tenant?->fullname}")
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn ($set) => $set('invoice_id', null)),
                    // CHỈ hiện cho loại "Nhắc đóng tiền" — biết ĐÚNG hoá đơn nào (1 hợp đồng có thể
                    // có nhiều hoá đơn qua từng tháng) để gửi Zalo kèm đủ chi tiết tiền phòng/điện/
                    // nước/nợ cũ giống hệt phiếu in (xem MinihouseZaloService::buildTemplateData()).
                    // Chỉ liệt kê hoá đơn CHƯA thanh toán đủ của đúng hợp đồng đã chọn ở trên.
                    Select::make('invoice_id')
                        ->label('Hoá đơn cần nhắc')
                        ->visible(fn (Get $get) => $get('type') === Reminder::TYPE_PAYMENT)
                        ->options(function (Get $get) {
                            $contractId = $get('contract_id');

                            if (blank($contractId)) {
                                return [];
                            }

                            // KHÔNG withoutGlobalScopes() — Invoice dùng SoftDeletes, bỏ scope sẽ
                            // cho chọn NHẦM 1 hoá đơn đã bị xoá mềm để gắn nhắc việc (Zalo/SMS/Portal
                            // sẽ nhắc khách về 1 hoá đơn không còn tồn tại). contract_id đã tự giới
                            // hạn đúng 1 hợp đồng nên không cần bỏ scope 'activeBuilding' ở đây nữa.
                            return Invoice::where('contract_id', $contractId)
                                ->whereIn('status', [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL])
                                ->orderByDesc('month')
                                ->get()
                                ->mapWithKeys(fn (Invoice $inv) => [
                                    $inv->id => 'Tháng ' . $inv->month?->format('m/Y') . ' — còn nợ ' . number_format($inv->remainingAmount(), 0, ',', '.') . 'đ',
                                ]);
                        })
                        ->helperText('Chọn Hợp đồng liên quan trước — chỉ hiện hoá đơn chưa thanh toán đủ của hợp đồng đó. Để trống thì tin Zalo chỉ có nội dung chung, không kèm số tiền chi tiết.')
                        ->searchable(),
                    Toggle::make('is_done')
                        ->label('Đã xử lý'),
                    Textarea::make('content')
                        ->label('Nội dung')
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
