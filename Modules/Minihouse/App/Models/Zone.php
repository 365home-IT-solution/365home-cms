<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    public function buildings(): HasMany
    {
        return $this->hasMany(Building::class);
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
