<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Filament\Pages\Setting;

use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\Vehicle;
use Modules\Minihouse\App\Models\VehicleRate;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Bảng giá gửi xe THEO TOÀ NHÀ: mỗi loại xe có phí tháng, số xe tối đa mỗi hợp đồng và tổng số chỗ của
// toà (để trống = không giới hạn). Phí tự vào hoá đơn (VehicleService::invoiceItems), vượt giới hạn chỉ
// CẢNH BÁO. Cùng mô hình ManageCameraSettings/ManageTtlockSettings.
class ManageVehicleRates extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Quản lý';
    protected static ?string $navigationLabel = 'Bảng giá gửi xe';
    protected static ?string $title           = 'Bảng giá gửi xe theo Toà nhà';
    protected static ?int    $navigationSort  = 99;

    protected static string $view = 'minihouse::filament.pages.setting.manage-vehicle-rates';

    public ?string $buildingId = null;

    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() || ($user?->can('page_manage_vehicle_rates') ?? false);
    }

    public function updatedBuildingId(): void
    {
        $this->fillFormForBuilding();
    }

    private function allowed(): bool
    {
        return filled($this->buildingId) && in_array((int) $this->buildingId, ActiveBuildingScope::permittedBuildingIds(), true);
    }

    private function fillFormForBuilding(): void
    {
        if (! $this->allowed()) {
            $this->buildingId = null;
            $this->form->fill([]);

            return;
        }

        $rates = VehicleRate::query()->where('building_id', (int) $this->buildingId)->get()->keyBy('vehicle_type');
        $state = [];

        foreach (Vehicle::TYPES as $type => $label) {
            $rate = $rates->get($type);
            $state[$type] = [
                'monthly_fee'      => $rate?->monthly_fee ?? 0,
                'max_per_contract' => $rate?->max_per_contract,
                'capacity'         => $rate?->capacity,
            ];
        }

        $this->form->fill(['rates' => $state]);
    }

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
        $sections = [];

        foreach (Vehicle::TYPES as $type => $label) {
            $sections[] = Forms\Components\Section::make($label)
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make("rates.{$type}.monthly_fee")->label('Phí gửi/tháng')->numeric()->minValue(0)->prefix('đ')->default(0)->required(),
                    Forms\Components\TextInput::make("rates.{$type}.max_per_contract")->label('Tối đa mỗi hợp đồng')->numeric()->minValue(0)->helperText('Để trống = không giới hạn'),
                    Forms\Components\TextInput::make("rates.{$type}.capacity")->label('Tổng số chỗ của toà')->numeric()->minValue(0)->helperText('Để trống = không giới hạn'),
                ]);
        }

        return $form->schema($sections)->statePath('data');
    }

    public function save(): void
    {
        if (! $this->allowed()) {
            Notification::make()->title('Chưa chọn Toà nhà cần cấu hình.')->danger()->send();

            return;
        }

        $data = $this->form->getState();

        foreach (Vehicle::TYPES as $type => $label) {
            $row = $data['rates'][$type] ?? [];

            VehicleRate::query()->updateOrCreate(
                ['building_id' => (int) $this->buildingId, 'vehicle_type' => $type],
                [
                    'monthly_fee'      => (float) ($row['monthly_fee'] ?? 0),
                    'max_per_contract' => filled($row['max_per_contract'] ?? null) ? (int) $row['max_per_contract'] : null,
                    'capacity'         => filled($row['capacity'] ?? null) ? (int) $row['capacity'] : null,
                ]
            );
        }

        Notification::make()->title('Đã lưu bảng giá gửi xe.')->success()->send();
    }
}
