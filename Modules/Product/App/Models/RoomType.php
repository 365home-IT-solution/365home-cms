<?php

declare(strict_types=1);

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use LogsAuditTrail;

    // Loại phòng đặc biệt đại diện cho phòng cho thuê DÀI HẠN của module MiniHouse (hợp đồng theo
    // tháng, không có lịch đặt theo đêm/khung giờ) — sản phẩm mượn bảng `products` để đồng nhất kiến
    // trúc dữ liệu với Home, nhưng KHÔNG được xuất hiện trong bất kỳ luồng đặt phòng ngắn hạn nào của
    // Home. Xem Product::booted() (Global Scope loại theo hằng số này) và
    // Modules\Minihouse\App\Support\HomestayBridge::ROOM_TYPE_SLUG (tham chiếu lại đúng giá trị này).
    public const MINIHOUSE_SLUG = 'minihouse';

    protected $fillable = [
        'slug',
        'name',
        'icon',
        'icon_url',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
