<?php

declare(strict_types=1);

namespace App\Filament\Pages\Setting;

use App\Models\CameraSetting;
use App\Models\Partner;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;

use function Filament\Support\is_app_url;

// Cấu hình địa chỉ server go2rtc/Frigate ngay trong web — thay cho việc phải sửa .env + restart
// server. Yêu cầu 2026-09-24: nhiều đối tác giờ có server Frigate RIÊNG (trước đây CHỈ 1 server dùng
// CHUNG cho mọi đối tác, App\Settings\CameraSettings) — trang này giờ đọc/ghi App\Models\CameraSetting
// (bảng phụ 1-1 theo partner_id).
//
// KHÔNG dùng Filament\Pages\SettingsPage (Spatie Settings, chỉ hợp với "1 dòng cấu hình DUY NHẤT
// toàn hệ thống") nữa — chuyển sang Page thường tự quản lý form theo ĐÚNG đối tác đang thao tác:
// tài khoản thường tự động bind vào đối tác của chính mình (không có lựa chọn nào khác, không cho
// đổi field); super_admin thấy thêm 1 Select để CHỌN đối tác cần cấu hình trước khi form còn lại
// hiện ra (super_admin không có "đối tác của chính họ" để mặc định).
class ManageCamera extends Page
{
    use Forms\Concerns\InteractsWithForms;
    use HasPageShield;

    protected static ?int $navigationSort = 97;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static string $view = 'filament.pages.setting.manage-camera';

    public ?string $partnerId = null;

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();

        // Tài khoản thường: KHÔNG có lựa chọn nào khác ngoài đối tác của chính mình — gán sẵn +
        // nạp luôn form, không cần bước "chọn đối tác" nào cả.
        if (! $user->isSuperAdmin()) {
            $this->partnerId = $user->partner_id;
            $this->fillFormForPartner();
        }

        // super_admin: chưa chọn đối tác nào — form còn lại (base_url/tài khoản Frigate) CHƯA hiện,
        // xem manage-camera.blade.php.
    }

    public function updatedPartnerId(): void
    {
        $this->fillFormForPartner();
    }

    private function fillFormForPartner(): void
    {
        if (blank($this->partnerId)) {
            $this->form->fill([]);

            return;
        }

        $this->form->fill(CameraSetting::forPartner($this->partnerId)->only([
            'base_url', 'api_key', 'username', 'password',
        ]));
    }

    public function partnerOptions(): array
    {
        return Partner::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Server go2rtc / Frigate')
                    ->description('Địa chỉ server chuyển đổi luồng camera CỦA ĐỐI TÁC NÀY (đặt tại nơi có camera hoặc VPS đã kết nối VPN tới camera) — mỗi đối tác có thể dùng 1 server hoàn toàn riêng, không chung với đối tác khác. Trang "Xem camera" và mục "Camera" dùng địa chỉ này để dựng link phát trực tiếp.')
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

    public function save(): void
    {
        if (blank($this->partnerId)) {
            Notification::make()->title('Chưa chọn đối tác cần cấu hình.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        $settings = CameraSetting::forPartner($this->partnerId);
        $settings->fill($data);
        $settings->partner_id = $this->partnerId;
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
