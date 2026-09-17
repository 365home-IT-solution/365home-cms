<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Exceptions\CannotDeleteReferencedRecordException;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

// "Khu vực" — gộp nhóm nhiều Toà nhà theo vị trí địa lý/đơn vị vận hành (VD "Khu Quận 3" gồm Toà A,
// B, C). KHÔNG có global scope theo toà nhà đang chọn (khác Building/Room...) vì Zone đứng TRÊN
// Building trong cây phân cấp — tự lọc theo zone sẽ vô nghĩa (đang chọn xem toà nào không quyết định
// được xem khu vực nào, phải làm ngược lại). Dùng ở User::rootBuildingIds() để gán quyền theo cả khu
// vực thay vì tick tay từng toà — xem minihouse_user_zones.
class Zone extends Model
{
    use SoftDeletes;
    use LogsMinihouseActivity;

    protected $table = 'minihouse_zones';

    protected $fillable = ['name', 'note'];

    // KHÔNG PHẢI hasMany() chuẩn — zone_id nằm ở bảng phụ minihouse_building_settings (uỷ quyền qua
    // Building::getAttribute(), xem Building.php), KHÔNG PHẢI 1 cột thật trên categories, nên Eloquent
    // không thể tự JOIN/WHERE trực tiếp categories.zone_id (từng gây lỗi "Unknown column
    // cms_categories.zone_id"). Trả về Eloquent\Builder tự dựng bằng subquery — vẫn dùng được
    // ->exists()/->count()/->get() bình thường, chỉ KHÔNG dùng được với ->withCount()/->counts() của
    // Filament (xem ZoneTable::table(), đã đổi cột đó sang ->state() tính trực tiếp).
    public function buildings(): Builder
    {
        return Building::query()->whereIn(
            'id',
            BuildingSetting::where('zone_id', $this->id)->pluck('category_id')
        );
    }

    // Zone dùng SoftDeletes — xoá chỉ set deleted_at, KHÔNG kích hoạt cascade FK thật ở CSDL. Còn Toà
    // nhà nào trỏ về khu vực này thì chặn xoá, tránh Building.zone_id trỏ về 1 Zone đã "biến mất"
    // (mọi $building->zone sau đó trả về NULL do SoftDeletingScope) — xem
    // CannotDeleteReferencedRecordException.
    protected static function booted(): void
    {
        static::deleting(function (Zone $zone) {
            if ($zone->buildings()->exists()) {
                throw new CannotDeleteReferencedRecordException(
                    'Khu vực này vẫn còn Toà nhà thuộc về nó — không thể xoá. Hãy chuyển hoặc xoá các Toà nhà đó trước.'
                );
            }
        });
    }

    protected function activityLabel(): string
    {
        return 'Khu vực ' . $this->name;
    }
}
