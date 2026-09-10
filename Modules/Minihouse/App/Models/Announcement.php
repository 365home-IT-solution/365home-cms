<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Thông báo chung chủ nhà tự đăng cho khách thuê xem trong Portal (VD cắt nước, bảo trì thang máy).
// building_id NULL = gửi TẤT CẢ khách thuê mọi toà — CỐ Ý KHÔNG áp ActiveBuildingScope/
// ScopedToActiveBuildingId ở đây: trait đó lọc "whereIn building_id" sẽ làm BIẾN MẤT các thông báo
// "gửi tất cả" (building_id NULL) khỏi danh sách khi đang lọc theo 1 toà cụ thể — sai ý định hiển thị
// (nhân viên toà A vẫn cần thấy thông báo chung áp dụng cho toà mình). Số dòng dự kiến rất ít, không
// cần lọc theo toà đang active ở header.
class Announcement extends Model
{
    protected $table = 'minihouse_announcements';

    protected $fillable = ['building_id', 'title', 'body', 'created_by'];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
