<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Minihouse\App\Models\ZaloSetting;

// Cấu hình Zalo OA/ZNS RIÊNG cho MiniHouse — CHỈ super_admin truy cập được (chứa bí mật gọi API thay
// mặt cả hệ thống, giống mức nhạy cảm của RoleResource). Hoàn toàn TÁCH BIỆT khỏi Zalo OA của Home —
// trang này KHÔNG đọc/ghi app/Settings/ZaloSettings.php hay config/services.php 'zalo' của Home, chỉ
// đọc/ghi ZaloSetting (bảng minihouse_zalo_settings) — xem MinihouseZaloService,
// MinihouseZaloTokenService.
class ZaloSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?string $navigationLabel = 'Cấu hình Zalo';
    protected static ?string $title           = 'Cấu hình Zalo OA/ZNS (MiniHouse)';
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
            'app_id', 'app_secret', 'refresh_token',
            'template_payment_reminder', 'template_contract_expiry', 'template_maintenance', 'template_otp',
        ]));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Tài khoản Zalo OA')
                    ->description('Lấy từ developers.zalo.me — ứng dụng liên kết với Zalo OA riêng của MiniHouse (khác OA của Home).')
                    ->columns(2)
                    ->schema([
                        TextInput::make('app_id')
                            ->label('App ID')
                            ->maxLength(255),
                        TextInput::make('app_secret')
                            ->label('App Secret')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        TextInput::make('refresh_token')
                            ->label('Refresh Token')
                            ->password()
                            ->revealable()
                            ->helperText('Lấy từ bước xác thực OAuth 1 lần với Zalo OA — hệ thống tự làm mới Access Token từ đây, không cần nhập tay Access Token.')
                            ->columnSpanFull(),
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
            ->title('Đã lưu cấu hình Zalo')
            ->success()
            ->send();
    }
}
