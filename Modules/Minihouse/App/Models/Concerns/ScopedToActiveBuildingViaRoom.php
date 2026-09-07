<?php

namespace Modules\Minihouse\App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Dùng cho model KHÔNG có cột building_id riêng nhưng có quan hệ room() (Tenant, Contract) — lọc
// qua whereHas('room', ...). Tenant chưa từng gắn phòng nào (room_id null) sẽ bị ẩn khi đang lọc
// theo toà nhà — chấp nhận được vì bộ lọc này chỉ để thu hẹp màn hình đang xem, không phải ranh
// giới dữ liệu (xem ActiveBuildingScope).
trait ScopedToActiveBuildingViaRoom
{
    protected static function bootScopedToActiveBuildingViaRoom(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            $builder->whereHas('room', function (Builder $query) {
                $query->whereIn('building_id', ActiveBuildingScope::activeBuildingIds());
            });
        });
    }
}
