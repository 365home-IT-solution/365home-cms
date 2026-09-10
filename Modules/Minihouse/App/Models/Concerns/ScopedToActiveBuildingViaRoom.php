<?php

namespace Modules\Minihouse\App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Dùng cho model KHÔNG có cột building_id riêng nhưng có quan hệ room() (Tenant, Contract) — lọc
// qua whereHas('room', ...).
trait ScopedToActiveBuildingViaRoom
{
    protected static function bootScopedToActiveBuildingViaRoom(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            // room_id NULL (VD Khách thuê vừa tạo, CHƯA gán hợp đồng/phòng nào) KHÔNG được lọc theo
            // toà — whereHas('room', ...) đơn thuần sẽ loại bỏ HẲN các bản ghi này (không có phòng
            // nào để so khớp), khiến khách VỪA TẠO bị 404 ngay khi chuyển sang trang Sửa vì chính
            // bản ghi mình vừa tạo bị chính global scope giấu đi. Cho qua khi chưa có phòng — đúng
            // cùng nguyên tắc "nhắc việc chung không gắn phòng nào thì không ẩn" đã áp dụng ở
            // Reminder::booted().
            $builder->where(fn (Builder $q) => $q
                ->whereNull('room_id')
                ->orWhereHas('room', fn (Builder $q2) => $q2->whereIn('building_id', ActiveBuildingScope::activeBuildingIds())));
        });
    }
}
