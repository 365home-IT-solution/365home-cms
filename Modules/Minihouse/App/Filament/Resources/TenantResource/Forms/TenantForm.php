<?php

namespace Modules\Minihouse\App\Filament\Resources\TenantResource\Forms;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Tabs;
use Filament\Forms\Components\Tabs\Tab;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Modules\Minihouse\App\Models\Room;
use Modules\Minihouse\App\Models\Tenant;

class TenantForm
{
    public static function form(Form $form): Form
    {
        return $form->schema([
            Tabs::make('Tenant')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Hồ sơ khách thuê')
                        ->columns(2)
                        ->schema([
                            TextInput::make('fullname')
                                ->label('Họ tên')
                                ->required()
                                ->maxLength(255),
                            TextInput::make('phone')
                                ->label('Số điện thoại')
                                ->tel()
                                ->maxLength(20),
                            TextInput::make('id_card_number')
                                ->label('Số CCCD/CMND')
                                ->maxLength(20),
                            DatePicker::make('date_of_birth')
                                ->label('Ngày sinh'),
                            Select::make('gender')
                                ->label('Giới tính')
                                ->options([
                                    Tenant::GENDER_MALE   => 'Nam',
                                    Tenant::GENDER_FEMALE => 'Nữ',
                                    Tenant::GENDER_OTHER  => 'Khác',
                                ]),
                            // CHỈ HIỂN THỊ khi SỬA khách đã có sẵn — không cho sửa tay. Giá trị này
                            // do hệ thống tự tính lại từ hợp đồng "Đang hiệu lực" (đứng tên hoặc ở
                            // cùng) mỗi khi hợp đồng thay đổi, xem ContractObserver::syncTenant().
                            // Cho sửa tay ở đây sẽ vô nghĩa (bị ghi đè âm thầm ngay sự kiện hợp đồng
                            // kế tiếp) và dễ gây hiểu nhầm — khách ĐÃ CÓ hợp đồng thì phải đổi
                            // phòng/gia hạn qua đúng trang Hợp đồng, không sửa trực tiếp ở đây.
                            Select::make('room_id')
                                ->label('Phòng đang ở')
                                ->relationship('room', 'code')
                                ->visible(fn (string $operation) => $operation === 'edit')
                                ->disabled()
                                ->dehydrated(false)
                                ->helperText('Tự động theo hợp đồng đang hiệu lực — sửa ở trang Hợp đồng.'),
                            // CHỈ HIỂN THỊ lúc TẠO MỚI khách thuê — tiện tạo luôn hợp đồng đầu tiên
                            // trong 1 lần thao tác thay vì phải mở thêm trang Hợp đồng riêng. Để
                            // trống thì chỉ lưu hồ sơ khách, không tạo hợp đồng gì cả (giữ đúng hành
                            // vi cũ). CreateTenant::afterCreate() đọc 4 field new_* này để tạo
                            // Contract — dehydrated(true) mặc định để mutateFormDataBeforeCreate() ở
                            // đó lấy được, rồi tự loại khỏi $data trước khi gọi Tenant::create().
                            Select::make('new_room_id')
                                ->label('Phòng thuê')
                                ->visible(fn (string $operation) => $operation === 'create')
                                ->options(fn () => Room::where('status', Room::STATUS_EMPTY)
                                    ->with('building')
                                    ->get()
                                    ->mapWithKeys(fn (Room $r) => [$r->id => "{$r->building?->name} - {$r->code}"]))
                                ->searchable()
                                ->live()
                                ->helperText('Chọn phòng để tự động tạo hợp đồng thuê ngay khi lưu khách thuê này — để trống nếu chỉ lưu hồ sơ, tạo hợp đồng sau.')
                                ->afterStateUpdated(function (Get $get, Set $set, $state) {
                                    $room = Room::find($state);

                                    if ($room && blank($get('new_monthly_price'))) {
                                        $set('new_monthly_price', $room->price);
                                    }
                                }),
                            TextInput::make('new_monthly_price')
                                ->label('Giá thuê / tháng')
                                ->numeric()
                                ->prefix('đ')
                                ->visible(fn (string $operation, Get $get) => $operation === 'create' && filled($get('new_room_id'))),
                            DatePicker::make('new_start_date')
                                ->label('Ngày bắt đầu thuê')
                                ->native(false)
                                ->default(now())
                                ->visible(fn (string $operation, Get $get) => $operation === 'create' && filled($get('new_room_id'))),
                            TextInput::make('new_deposit_amount')
                                ->label('Tiền cọc')
                                ->numeric()
                                ->prefix('đ')
                                ->default(0)
                                ->visible(fn (string $operation, Get $get) => $operation === 'create' && filled($get('new_room_id'))),
                            TextInput::make('occupation')
                                ->label('Nghề nghiệp')
                                ->maxLength(255),
                            TextInput::make('workplace')
                                ->label('Nơi làm việc')
                                ->maxLength(255),
                            Textarea::make('note')
                                ->label('Ghi chú')
                                ->columnSpanFull(),

                            // Nút "Quét CCCD" đặt ở HEADER TRANG (CreateTenant/EditTenant), không
                            // gắn Actions::make() lồng trong Tabs — cơ chế mount action gắn trong
                            // field (mountFormComponentAction qua /livewire/update) bị lỗi 419 "Page
                            // Expired" ngay cả trên tab ẩn danh hoàn toàn mới (đã kiểm tra kỹ, không
                            // phải do cache/session cũ). Action cấp trang (đọc/ghi qua $this->form,
                            // giống EditTenant đã chạy ổn định) không đụng cơ chế này.
                            FileUpload::make('id_card_front')
                                ->label('CCCD mặt trước')
                                ->image()
                                ->directory('minihouse/tenants')
                                ->disk('public'),
                            FileUpload::make('id_card_back')
                                ->label('CCCD mặt sau')
                                ->image()
                                ->directory('minihouse/tenants')
                                ->disk('public'),
                        ]),

                    Tab::make('Địa chỉ & liên hệ khẩn cấp')
                        ->columns(2)
                        ->schema([
                            TextInput::make('hometown')
                                ->label('Quê quán')
                                ->maxLength(255),
                            TextInput::make('permanent_address')
                                ->label('Nơi thường trú')
                                ->helperText('Theo CCCD — cần khi khai báo tạm trú.')
                                ->maxLength(255)
                                ->columnSpanFull(),
                            TextInput::make('emergency_contact_name')
                                ->label('Người liên hệ khẩn cấp')
                                ->maxLength(255),
                            TextInput::make('emergency_contact_phone')
                                ->label('SĐT liên hệ khẩn cấp')
                                ->tel()
                                ->maxLength(20),
                        ]),

                    // Trách nhiệm pháp lý của chủ trọ — khai báo tạm trú cho khách thuê trong 12-24h
                    // (qua VNeID/công an khu vực). Tách tab riêng để không bị bỏ sót khi tạo khách mới.
                    Tab::make('Khai báo tạm trú')
                        ->columns(2)
                        ->schema([
                            Toggle::make('residence_declared')
                                ->label('Đã khai báo tạm trú')
                                ->live(),
                            DatePicker::make('residence_declared_at')
                                ->label('Ngày khai báo')
                                ->visible(fn ($get) => (bool) $get('residence_declared')),
                        ]),

                    // Chỉ XEM ở đây — sửa/tạo hợp đồng vẫn qua đúng 1 chỗ duy nhất (ContractResource,
                    // có đủ tab riêng: người ở cùng/nội dung/giấy tờ/thanh lý) để tránh 2 form khác
                    // nhau cùng sửa 1 bản ghi rồi lệch nhau. Vì vậy dùng Placeholder hiện danh sách
                    // (kèm link "Xem hợp đồng"), không dùng Repeater (Repeater sẽ cho sửa/xoá tại đây).
                    Tab::make('Lịch sử thuê')
                        ->schema([
                            Placeholder::make('rental_history')
                                ->label('')
                                ->content(fn (?Tenant $record) => view('minihouse::filament.tenant-rental-history', [
                                    'contracts' => $record
                                        ? $record->contracts()->with('room')->orderByDesc('start_date')->get()
                                        : collect(),
                                ]))
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }
}
