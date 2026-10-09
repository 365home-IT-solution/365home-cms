<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages\Setting;

use App\Filament\Support\CameraGatewayForm;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\CameraSetting;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Mirror App\Filament\Pages\Setting\ManageCamera (Home) — khác đúng 1 chỗ: bản Home cho super_admin
// CHỌN ĐỐI TÁC (nhiều đối tác thật, mỗi đối tác 1 server), còn ở đây MiniHouse chỉ có 1 đối tác nội
// bộ cố định nên lọc theo đối tác vô nghĩa — đổi Select sang CHỌN TOÀ NHÀ (mỗi Toà nhà là 1 địa điểm
// vật lý riêng, có thể có server Frigate/go2rtc khác nhau). Xem Modules\Minihouse\App\Models\
// CameraSetting (khoá chính = building_id) và Camera::resolveCameraSettings().
class ManageCameraSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-video-camera';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Cấu hình Camera';

    protected static ?string $title = 'Cấu hình Camera theo Toà nhà';

    protected static ?int $navigationSort = 97;

    protected static string $view = 'minihouse::filament.pages.setting.manage-camera-settings';

    // string (không phải int) — options HTML <select> luôn gửi lên dạng chuỗi, giống hệt kiểu
    // $partnerId ở bản Home (App\Filament\Pages\Setting\ManageCamera), tránh Livewire tự ép kiểu lỗi
    // khi giá trị rỗng "— Chọn Toà nhà —" được chọn.
    public ?string $buildingId = null;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_manage_camera_settings') ?? false);
    }

    public function updatedBuildingId(): void
    {
        $this->fillFormForBuilding();
    }

    private function fillFormForBuilding(): void
    {
        if (blank($this->buildingId)) {
            $this->form->fill([]);

            return;
        }

        $this->form->fill(CameraGatewayForm::fill(CameraSetting::forBuilding((int) $this->buildingId)));
    }

    // Chỉ liệt kê Toà nhà tài khoản đang đăng nhập ĐƯỢC PHÉP quản lý — giống hệt phạm vi dùng ở
    // CameraForm/CameraResource (ActiveBuildingScope::permittedBuildingIds()), tránh lộ/sửa cấu hình
    // camera của Toà nhà không thuộc quyền quản lý.
    public function buildingOptions(): array
    {
        return Building::withoutGlobalScope('activeBuilding')
            ->whereIn('id', ActiveBuildingScope::permittedBuildingIds())
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
                ->visible(fn (): bool => filled($this->buildingId))
                ->action(fn () => CameraGatewayForm::notifyTestResult(CameraSetting::forBuilding((int) $this->buildingId))),
        ];
    }

    public function save(): void
    {
        if (blank($this->buildingId)) {
            Notification::make()->title('Chưa chọn Toà nhà cần cấu hình.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        $settings = CameraSetting::forBuilding((int) $this->buildingId);
        CameraGatewayForm::apply($settings, $data);
        $settings->building_id = (int) $this->buildingId;
        $settings->save();

        Notification::make()->title('Đã lưu cấu hình camera.')->success()->send();
    }
}
