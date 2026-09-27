<?php

declare(strict_types=1);

namespace Modules\Minihouse\App\Support;

use Illuminate\Support\Collection;
use Modules\Minihouse\App\Models\Building;
use Modules\Minihouse\App\Models\TtlockSetting;

// Danh sách Toà nhà (đang xem theo bộ lọc header) ĐÃ cấu hình + bật tài khoản TTLock — nguồn duy nhất
// cho các trang Filament khoá thông minh (mỗi trang trước đây tự lặp lại vòng "for mỗi tài khoản").
class TtlockLocks
{
    // Dịch vụ TTLock của 1 Toà nhà — null nếu tài khoản hiện tại KHÔNG có quyền trên Toà nhà đó (chặn
    // truyền building_id tuỳ ý qua URL/Livewire) hoặc Toà nhà chưa cấu hình TTLock.
    public static function service(?int $buildingId): ?\Modules\TTLock\App\Services\TTLockService
    {
        if (! $buildingId || ! in_array($buildingId, ActiveBuildingScope::permittedBuildingIds(), true)) {
            return null;
        }

        return \Modules\TTLock\App\Services\TTLockService::forBuilding($buildingId);
    }

    /** @return Collection<int, Building> */
    public static function buildings(): Collection
    {
        $ids = ActiveBuildingScope::activeBuildingIds();

        $configured = TtlockSetting::query()
            ->whereIn('building_id', $ids)
            ->where('is_active', true)
            ->get()
            ->filter(fn (TtlockSetting $s) => $s->isConfigured())
            ->pluck('building_id');

        return Building::withoutGlobalScope('activeBuilding')
            ->whereIn('id', $configured)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
