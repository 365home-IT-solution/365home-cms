<?php

namespace Modules\Minihouse\App\Filament\Pages;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Minihouse\App\Models\SmsSetting;

// Cấu hình SMS Brandname RIÊNG cho MiniHouse (eSMS.vn) — CHỈ super_admin truy cập được (chứa bí mật
// gọi API thay mặt cả hệ thống, cùng mức nhạy cảm với ZaloSettingsPage). Hoàn toàn TÁCH BIỆT khỏi
// mọi cấu hình SMS khác nếu Home có — chỉ đọc/ghi SmsSetting (bảng minihouse_sms_settings), xem
// MinihouseSmsService.
class SmsSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-device-phone-mobile';
    protected static ?string $navigationGroup = 'Hệ thống';
    protected static ?string $navigationLabel = 'Cấu hình SMS';
    protected static ?string $title           = 'Cấu hình SMS Brandname (MiniHouse)';
    protected static ?int $navigationSort     = 97;

    protected static string $view = 'minihouse::filament.pages.sms-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->form->fill(SmsSetting::current()->only(['api_key', 'secret_key', 'brandname']));
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Tài khoản eSMS.vn')
                    ->description('Lấy từ tài khoản eSMS Business đã đăng ký — Brandname phải đã được eSMS DUYỆT trước khi dùng, chưa duyệt sẽ gửi thất bại.')
                    ->columns(1)
                    ->schema([
                        TextInput::make('brandname')
                            ->label('Brandname')
                            ->maxLength(20)
                            ->helperText('Tên hiển thị làm người gửi trên tin nhắn — VD: MINIHOUSE. Tối đa 11 ký tự không dấu theo quy định của nhà mạng, eSMS sẽ báo lỗi nếu đăng ký sai.'),
                        TextInput::make('api_key')
                            ->label('API Key')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                        TextInput::make('secret_key')
                            ->label('Secret Key')
                            ->password()
                            ->revealable()
                            ->maxLength(255),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SmsSetting::current()->update($data);

        Notification::make()
            ->title('Đã lưu cấu hình SMS')
            ->success()
            ->send();
    }
}
