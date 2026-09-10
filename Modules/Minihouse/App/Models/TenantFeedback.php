<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaRoom;

// Phản hồi/đánh giá khách thuê gửi qua 1 trong 2 kênh: (1) link công khai KHÔNG cần đăng nhập (xem
// TenantFeedbackController) — tenant_id NULL, chỉ tự ghi tên/SĐT (tuỳ chọn); (2) Portal khách thuê ĐÃ
// đăng nhập (xem TenantPortalController::storeFeedback()) — tenant_id có giá trị, để khi chủ nhà xử
// lý xong (is_reviewed=true) báo lại được ĐÚNG khách đó trong Portal (xem TenantFeedbackObserver).
class TenantFeedback extends Model
{
    use ScopedToActiveBuildingViaRoom;

    protected $table = 'minihouse_tenant_feedbacks';

    protected $fillable = ['room_id', 'tenant_id', 'tenant_name', 'tenant_phone', 'rating', 'content', 'is_reviewed', 'staff_note'];

    protected $casts = [
        'rating'      => 'integer',
        'is_reviewed' => 'boolean',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
