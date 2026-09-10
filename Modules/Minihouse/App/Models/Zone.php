<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
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

    protected function activityLabel(): string
    {
        return 'Khu vực ' . $this->name;
    }
}
