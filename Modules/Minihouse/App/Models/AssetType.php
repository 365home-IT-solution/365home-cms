<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;

// Danh mục "Loại tài sản" (tủ lạnh, máy lạnh, giường, tủ...) — chọn lại tên từ đây khi khai tài sản
// cho từng phòng (xem RoomForm's Repeater 'assets'), không phải gõ tay tự do mỗi lần. Không có quan
// hệ trực tiếp tới Room/RoomAsset — chỉ là nguồn gợi ý tên (RoomAsset.name vẫn là cột string bình
// thường, xem migration create_minihouse_asset_types_table).
class AssetType extends Model
{
    protected $table = 'minihouse_asset_types';

    protected $fillable = ['name'];
}
