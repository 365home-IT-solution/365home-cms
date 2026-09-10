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

            // whereHas('contract.room', ...) trước đây tự áp CẢ scope SoftDeletes của Contract lẫn
            // Room vào 2 vế EXISTS lồng nhau — 1 bản ghi (VD Khai báo lưu trú) có hợp đồng đã bị xoá
            // mềm sẽ BIẾN MẤT KHỎI TOÀN BỘ danh sách đang lọc theo toà (kể cả tài khoản có quyền xem
            // đầy đủ), không chỉ hiện thiếu vài trường như các chỗ dùng "?->" khác — nghiêm trọng hơn
            // vì đây là dữ liệu có nghĩa vụ pháp lý (khai báo tạm trú), không nên tự "mất tích" khỏi
            // danh sách chỉ vì hợp đồng liên quan đã lưu trữ/xoá. Bỏ scope ở cả 2 tầng để hợp
            // đồng/phòng đã xoá mềm vẫn được tính vào kết quả EXISTS.
            $builder->whereHas('contract', function (Builder $contractQuery) {
                $contractQuery->withoutGlobalScopes()
                    ->whereHas('room', function (Builder $roomQuery) {
                        $roomQuery->withoutGlobalScopes()
                            ->whereIn('building_id', ActiveBuildingScope::activeBuildingIds());
                    });
            });
        });
    }
}
