<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Tài sản/nội thất gắn với 1 phòng cụ thể (tủ lạnh, máy lạnh, giường, tủ...) — theo dõi tình trạng
// để biết cần thay/sửa gì khi khách trả phòng, hoặc làm căn cứ đối chiếu khi phát sinh phụ thu hư
// hỏng (xem SurchargeResource/ContractForm — phụ thu vẫn nhập tay riêng, đây chỉ là NGUỒN THAM
// KHẢO tình trạng tài sản, không tự động sinh phụ thu).
class RoomAsset extends Model
{
    public const CONDITION_GOOD        = 'tot';
    public const CONDITION_DAMAGED     = 'hu_hong';
    public const CONDITION_MAINTENANCE = 'dang_sua';

    protected $table = 'minihouse_room_assets';

    protected $fillable = ['room_id', 'name', 'condition', 'note'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }
}
