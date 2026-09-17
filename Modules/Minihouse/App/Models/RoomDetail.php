<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;

// Bảng phụ 1-1 với products (khoá chính = product_id) giữ các cột riêng của việc cho thuê dài hạn
// mà Product (đặt phòng ngắn hạn) không có — floor/position_row/position_col (vị trí ô lưới sơ đồ
// tầng), status (trạng thái thuê), photos. KHÔNG dùng trực tiếp ở nơi khác ngoài Room — mọi truy cập
// đều đi qua Room::getAttribute()/setAttribute() (xem Room.php) để giữ nguyên API cũ
// ($room->floor, $room->status...) cho toàn bộ code hiện có, không cần sửa gì thêm.
class RoomDetail extends Model
{
    protected $table = 'minihouse_room_details';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['product_id', 'floor', 'position_row', 'position_col', 'status', 'photos'];

    protected $casts = [
        'photos' => 'array',
    ];
}
