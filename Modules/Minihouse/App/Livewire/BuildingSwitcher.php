<?php

namespace Modules\Minihouse\App\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Nút "Chuyển đổi toà nhà" ở topbar panel minihouse-admin — nhân bản đúng UX của
// App\Livewire\BranchSwitcher (Home): chọn 1/nhiều trong số toà nhà tài khoản ĐƯỢC PHÉP quản lý
// (ActiveBuildingScope::permittedBuildingIds(), xem User::rootBuildingIds()) để thu hẹp màn hình
// đang xem, lưu ở session. Mặc định (chưa từng bấm Áp dụng) = chọn hết trong số được phép.
class BuildingSwitcher extends Component
{
    public array $selected = [];

    public function mount(): void
    {
        $permitted = ActiveBuildingScope::permittedBuildingIds();
        $stored    = session(ActiveBuildingScope::SESSION_KEY);

        $this->selected = ! empty($stored)
            ? array_values(array_intersect($permitted, $stored))
            : $permitted;
    }

    public function buildings(): Collection
    {
        $ids = ActiveBuildingScope::permittedBuildingIds();

        if (empty($ids)) {
            return collect();
        }

        // withoutGlobalScopes() — Building tự lọc theo toà nhà đang chọn (ScopedToActiveBuilding),
        // ở đây cần liệt kê đúng những toà ĐƯỢC PHÉP (permittedBuildingIds() đã tính đúng rồi),
        // không để scope lọc chồng thêm lần nữa theo lựa chọn CŨ đang lưu trong session.
        return Building::withoutGlobalScopes()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    public function selectAll(): void
    {
        $this->selected = ActiveBuildingScope::permittedBuildingIds();
    }

    public function apply(): void
    {
        $permitted = ActiveBuildingScope::permittedBuildingIds();
        $safe      = array_values(array_intersect($permitted, $this->selected));

        // Không cho phép tự bỏ chọn hết — coi như chưa chọn gì (mặc định = tất cả được phép), tránh
        // tự khoá mất toàn bộ dữ liệu của chính mình do bấm nhầm.
        if (empty($safe)) {
            $safe = $permitted;
        }

        session([ActiveBuildingScope::SESSION_KEY => $safe]);
        $this->selected = $safe;

        $this->redirect(Filament::getUrl(), navigate: false);
    }

    public function render(): View
    {
        return view('minihouse::livewire.building-switcher');
    }
}
