<?php

declare(strict_types=1);

namespace App\Filament\Pages\Setting;

use App\Filament\Support\CameraGatewayForm;
use App\Models\CameraSetting;
use App\Models\Partner;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
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
        if (blank($this->partnerId) || ! Partner::query()
            ->whereKey($this->partnerId)
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->exists()) {
            $this->partnerId = null;
            $this->form->fill([]);

            return;
        }

        $this->form->fill(CameraGatewayForm::fill(CameraSetting::forPartner($this->partnerId)));
    }

    public function partnerOptions(): array
    {
        return Partner::query()
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(CameraGatewayForm::schema())
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testConnection')
                ->label('Kiểm tra kết nối')
                ->icon('heroicon-o-signal')
                ->color('gray')
                ->visible(fn (): bool => filled($this->partnerId))
                ->action(fn () => CameraGatewayForm::notifyTestResult(CameraSetting::forPartner($this->partnerId))),
        ];
    }

    public function save(): void
    {
        if (blank($this->partnerId) || ! Partner::query()
            ->whereKey($this->partnerId)
            ->where('partner_type', Partner::TYPE_HOMESTAY)
            ->exists()) {
            Notification::make()->title('Chưa chọn đối tác cần cấu hình.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        $settings = CameraSetting::forPartner($this->partnerId);
        CameraGatewayForm::apply($settings, $data);
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
