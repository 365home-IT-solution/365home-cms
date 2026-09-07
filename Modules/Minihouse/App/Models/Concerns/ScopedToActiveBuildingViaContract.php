<?php

namespace Modules\Minihouse\App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Modules\Minihouse\App\Support\ActiveBuildingScope;

// Dùng cho model có quan hệ contract() (BẮT BUỘC, không null) nhưng không có room_id/building_id
// trực tiếp — vd ResidenceDeclaration. Lọc qua contract.room.building_id.
trait ScopedToActiveBuildingViaContract
{
    protected static function bootScopedToActiveBuildingViaContract(): void
    {
        static::addGlobalScope('activeBuilding', function (Builder $builder) {
            if (! ActiveBuildingScope::shouldFilter()) {
                return;
            }

            $builder->whereHas('contract.room', function (Builder $query) {
                $query->whereIn('building_id', ActiveBuildingScope::activeBuildingIds());
            });
        });
    }
}
