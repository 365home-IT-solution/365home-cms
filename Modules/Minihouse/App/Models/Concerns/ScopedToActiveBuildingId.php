<?php

namespace Modules\Minihouse\App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Dùng cho model có SẴN cột building_id (Room, Surcharge) — lọc thẳng theo cột đó, không cần join.
trait ScopedToActiveBuildingId
{
    protected static function bootScopedToActiveBuildingId(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            $builder->whereIn($builder->getModel()->getTable() . '.building_id', ActiveBuildingScope::activeBuildingIds());
        });
    }
}
