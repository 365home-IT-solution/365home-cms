<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Minihouse\App\Models\ZaloSetting;

// Cấu hình ZNS cho MiniHouse — CHỈ super_admin truy cập được. KHÔNG còn phần "Tài khoản Zalo OA"
// (App ID/App Secret/Refresh Token) ở trang này nữa: MiniHouse dùng CHUNG 1 Zalo OA với Home (xem
// MinihouseZaloTokenService — uỷ quyền cho App\Services\ZaloTokenService), tài khoản OA đó cấu hình
// qua .env (ZALO_APP_ID/APP_SECRET/REFRESH_TOKEN) của toàn hệ thống, không sửa ở đây được nữa — trước
// đây có 2 nơi quản lý ĐỘC LẬP (ở đây + .env) cùng cầm refresh_token gốc, refresh_token của Zalo chỉ
// dùng 1 lần nên 2 bên liên tục giẫm chân nhau, làm CẢ Home lẫn MiniHouse bị lỗi "Invalid refresh
// token." lặp lại (xem docs/be-minihouse-contract-signing.md). Trang này giờ CHỈ còn 4 mẫu ZNS
// Template ID RIÊNG của MiniHouse (đọc/ghi bảng minihouse_zalo_settings qua ZaloSetting) — vẫn cần
// duyệt riêng dù chung 1 OA, không liên quan gì tới việc quản lý token.
class ZaloSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?string $navigationLabel = 'Mẫu ZNS MiniHouse';
    protected static ?string $title           = 'Mẫu tin ZNS (MiniHouse)';
    protected static ?int $navigationSort     = 96;

    protected static string $view = 'minihouse::filament.pages.zalo-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(ZaloSetting::current()->only([
            'template_payment_reminder', 'template_contract_expiry', 'template_maintenance', 'template_otp',
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Tài khoản Zalo OA')
                    ->schema([
                        Placeholder::make('shared_oa_note')
                            ->label('')
                            ->content('Toà nhà MiniHouse hiện dùng CHUNG 1 Zalo OA với hệ thống Home — cấu hình App ID/App Secret/Refresh Token nằm trong biến môi trường (.env) của server, liên hệ đội kỹ thuật nếu cần đổi.'),
                    ]),

                Section::make('Mẫu tin ZNS (đã được Zalo duyệt)')
                    ->description('Mỗi loại nhắc việc cần 1 mẫu ZNS riêng đã nộp và được Zalo phê duyệt trước — để trống loại nào thì loại đó không gửi Zalo, chỉ báo trong hệ thống như bình thường.')
                    ->columns(1)
                    ->schema([
                        TextInput::make('template_payment_reminder')
                            ->label('Mẫu — Nhắc đóng tiền')
                            ->maxLength(50),
                        TextInput::make('template_contract_expiry')
                            ->label('Mẫu — Nhắc hết hạn hợp đồng')
                            ->maxLength(50),
                        TextInput::make('template_maintenance')
                            ->label('Mẫu — Nhắc bảo trì')
                            ->maxLength(50),
                    ]),

                Section::make('Mẫu OTP (Portal khách thuê)')
                    ->description('Zalo duyệt mẫu OTP theo NHÓM RIÊNG (chỉ chứa mã xác thực + thời hạn hiệu lực, không kèm nội dung nào khác) — không dùng chung được với 3 mẫu nhắc việc ở trên. Để trống thì khách thuê chưa đăng nhập được vào Portal qua Zalo.')
                    ->columns(1)
                    ->schema([
                        TextInput::make('template_otp')
                            ->label('Mẫu — OTP đăng nhập')
                            ->maxLength(50),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        ZaloSetting::current()->update($data);

        Notification::make()
            ->title('Đã lưu mẫu ZNS')
            ->success()
            ->send();
    }
}
