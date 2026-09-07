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
                            // CHỈ HIỂN THỊ — không cho sửa tay. Giá trị này do hệ thống tự tính lại
                            // từ hợp đồng "Đang hiệu lực" (đứng tên hoặc ở cùng) mỗi khi hợp đồng
                            // thay đổi, xem ContractObserver::syncTenant(). Cho sửa tay ở đây sẽ vô
                            // nghĩa (bị ghi đè âm thầm ngay sự kiện hợp đồng kế tiếp) và dễ gây hiểu
                            // nhầm — muốn đổi phòng cho khách thì phải sửa/tạo Hợp đồng, không sửa
                            // trực tiếp ở đây.
                            Select::make('room_id')
                                ->label('Phòng đang ở')
                                ->relationship('room', 'code')
                                ->disabled()
                                ->dehydrated(false)
                                ->helperText('Tự động theo hợp đồng đang hiệu lực — sửa ở trang Hợp đồng.'),
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
