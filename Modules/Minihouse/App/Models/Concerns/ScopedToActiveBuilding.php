<?php

namespace Modules\Minihouse\App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Dùng cho CHÍNH Model Building — lọc theo id nằm trong danh sách đang chọn ở bộ lọc toà nhà header
// (xem ActiveBuildingScope). Khác ScopedToActiveBuildingId (dùng cho model có cột building_id).
trait ScopedToActiveBuilding
{
    protected static function bootScopedToActiveBuilding(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            $builder->whereIn($builder->getModel()->getTable() . '.id', ActiveBuildingScope::activeBuildingIds());
        });
    }
}
