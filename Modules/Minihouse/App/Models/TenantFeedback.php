<?php

namespace Modules\Minihouse\App\Models;

use App\Models\Concerns\HasReviewImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingViaRoom;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

// Phản hồi/đánh giá khách thuê gửi qua 1 trong 2 kênh: (1) link công khai KHÔNG cần đăng nhập (xem
// TenantFeedbackController) — tenant_id NULL, chỉ tự ghi tên/SĐT (tuỳ chọn); (2) Portal khách thuê ĐÃ
// đăng nhập (xem TenantPortalController::storeFeedback()) — tenant_id có giá trị, để khi chủ nhà xử
// lý xong (is_reviewed=true) báo lại được ĐÚNG khách đó trong Portal (xem TenantFeedbackObserver).
class TenantFeedback extends Model implements HasMedia
{
    use Concerns\LogsMinihouseActivity;
    use HasReviewImages, InteractsWithMedia {
        // Cả 2 trait cùng khai báo 2 hàm này — dùng bản của HasReviewImages (collection + thumb/card webp).
        HasReviewImages::registerMediaCollections insteadof InteractsWithMedia;
        HasReviewImages::registerMediaConversions insteadof InteractsWithMedia;
    }
    use ScopedToActiveBuildingViaRoom;

    // Ảnh đính kèm phản hồi (vd ảnh sự cố) — cùng giới hạn với đánh giá phòng Homestay (RoomRating).
    public const IMAGE_COLLECTION = 'images';

    public const MAX_IMAGES = 5;

    public const MAX_IMAGE_KB = 5120;

    public const IMAGE_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

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
