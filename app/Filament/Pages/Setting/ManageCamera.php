<?php

declare(strict_types=1);

namespace App\Filament\Pages\Setting;

use App\Settings\CameraSettings;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\SettingsPage;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;

use function Filament\Support\is_app_url;

// Cấu hình địa chỉ server go2rtc/Frigate ngay trong web — thay cho việc phải sửa .env + restart
// server (App\Settings\CameraSettings). Nhân viên vận hành chỉ cần vào đây đổi địa chỉ khi đổi
// mạng/server, không cần quyền SSH.
class ManageCamera extends SettingsPage
{
    use HasPageShield;

    protected static string $settings = CameraSettings::class;

    protected static ?int $navigationSort = 97;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Server go2rtc / Frigate')
                    ->description('Địa chỉ server chuyển đổi luồng camera (đặt tại nơi có camera hoặc VPS đã kết nối VPN tới camera). Trang "Xem camera" và mục "Camera" dùng địa chỉ này để dựng link phát trực tiếp.')
                    ->schema([
                        Forms\Components\TextInput::make('base_url')
                            ->label('Địa chỉ server')
                            ->placeholder('http://192.168.1.10:1984')
                            ->helperText('Ví dụ: http://<IP-server>:1984 (go2rtc) hoặc http://<IP-server>:5000 (Frigate). Không thêm dấu / ở cuối.')
                            ->url()
                            ->required()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('api_key')
                            ->label('API key (chỉ dùng cho go2rtc trần, KHÔNG áp dụng cho Frigate)')
                            ->password()
                            ->revealable()
                            ->helperText('Frigate không dùng API key — bỏ qua ô này nếu server là Frigate, xem 2 ô tài khoản bên dưới.')
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Tài khoản đăng nhập Frigate')
                    ->description('Frigate xác thực bằng tài khoản/mật khẩu (không phải API key). Server 365home-cms tự đăng nhập bằng tài khoản này để lấy luồng camera thay bạn — trình duyệt của người xem KHÔNG cần đăng nhập Frigate. Đây là tài khoản Frigate thật (trang đăng nhập bạn thấy khi vào thẳng domain Frigate), KHÔNG phải tài khoản Frigate+.')
                    ->schema([
                        Forms\Components\TextInput::make('username')
                            ->label('Tài khoản Frigate'),

                        Forms\Components\TextInput::make('password')
                            ->label('Mật khẩu Frigate')
                            ->password()
                            ->revealable(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(CameraSettings $settings = null): void
    {
        $data = $this->form->getState();

        $settings->fill($data);
        $settings->save();

        Notification::make()->title('Đã lưu cấu hình camera.')->success()->send();

        $this->redirect(static::getUrl(), navigate: FilamentView::hasSpaMode() && is_app_url(static::getUrl()));
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Cấu hình web';
    }

    public static function getNavigationLabel(): string
    {
        return 'Camera';
    }

    public function getTitle(): string|Htmlable
    {
        return 'Cấu hình Camera';
    }
}
