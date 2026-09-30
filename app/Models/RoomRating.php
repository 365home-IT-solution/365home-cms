<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasReviewImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class RoomRating extends Model implements HasMedia
{
    use HasReviewImages, InteractsWithMedia {
        // Cả 2 trait cùng khai báo 2 hàm này — dùng bản của HasReviewImages (collection + thumb/card webp).
        HasReviewImages::registerMediaCollections insteadof InteractsWithMedia;
        HasReviewImages::registerMediaConversions insteadof InteractsWithMedia;
    }

    // Ảnh đính kèm đánh giá — dùng chung 1 nguồn cho API khách, API admin và Filament.
    public const IMAGE_COLLECTION = 'images';

    public const MAX_IMAGES = 5;

    public const MAX_IMAGE_KB = 5120;

    public const IMAGE_MIMES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $fillable = [
        'customer_id', 'room_id', 'star', 'comment',
        'admin_reply', 'replied_by', 'replied_at',
    ];

    protected $casts = [
        'star'       => 'integer',
        'replied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Xoá đánh giá từ bất kỳ đâu (API khách, API admin, Filament) đều phải tính lại điểm phòng.
        static::deleted(function (RoomRating $rating) {
            $avg = static::where('room_id', $rating->room_id)->avg('star');

            Product::withoutGlobalScopes()->where('id', $rating->room_id)->update([
                'rating_score' => $avg !== null ? round((float) $avg, 1) : null,
            ]);
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function room(): BelongsTo
    {
        // Lịch sử/tham chiếu tới phòng vẫn phải đọc được khi phòng mất chi nhánh (scope has_branch chỉ để ẩn khỏi
        // danh sách) và khi đó là phòng MiniHouse (scope exclude_minihouse chỉ để loại khỏi luồng đặt phòng ngắn hạn).
        return $this->belongsTo(Product::class, 'room_id')->withoutGlobalScopes(['has_branch', 'exclude_minihouse']);
    }

    // Đánh giá phòng MiniHouse và Homestay dùng CHUNG bảng room_ratings (đều là dòng của products) nhưng
    // là 2 nghiệp vụ riêng, quản trị ở 2 nơi riêng — mỗi nơi PHẢI lọc đúng phần của mình.
    public function scopeForMinihouse($query)
    {
        return $query->whereHas('room', fn ($room) => $room->whereHas('roomType', fn ($t) => $t->where('slug', RoomType::MINIHOUSE_SLUG)));
    }

    public function scopeForHomestay($query)
    {
        return $query->whereDoesntHave('room', fn ($room) => $room->whereHas('roomType', fn ($t) => $t->where('slug', RoomType::MINIHOUSE_SLUG)));
    }

    public function repliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'replied_by');
    }
}
