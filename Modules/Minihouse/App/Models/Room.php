<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Minihouse\App\Exceptions\CannotDeleteReferencedRecordException;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;

class Room extends Model
{
    use SoftDeletes;
    use ScopedToActiveBuildingId;
    use LogsMinihouseActivity;

    public const STATUS_EMPTY    = 'trong';
    public const STATUS_RESERVED = 'dat_coc';
    public const STATUS_RENTED   = 'dang_thue';
    public const STATUS_REPAIR   = 'bao_tri';

    protected $table = 'minihouse_rooms';

    protected $fillable = ['building_id', 'code', 'floor', 'position_row', 'position_col', 'area', 'price', 'status', 'note', 'photos'];

    // area/price là cột decimal ở CSDL nhưng KHÔNG cần ép cố định số lẻ như 'decimal:2' — cast đó
    // luôn trả về chuỗi có đủ N số thập phân (VD "2500000.00"), hiện ra ",00" giả dù giá trị thật
    // là số nguyên. 'float' giữ đúng số lẻ THẬT (nếu area = 20.5 vẫn hiện 20.5), số nguyên thì hiện
    // gọn "20"/"2500000" như bình thường.
    protected $casts = [
        'photos' => 'array',
        'area'   => 'float',
        'price'  => 'float',
    ];

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'minihouse_room_amenity');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(RoomAsset::class);
    }

    // Room.status chỉ nên do ContractObserver::syncRoom() tự cập nhật theo hợp đồng thật — cho sửa
    // tay TỰ DO (đổi thành "Trống"/"Đã khoá" trong khi vẫn còn hợp đồng "Đang hiệu lực") sẽ khiến
    // phòng hiện SAI là còn trống ở mọi nơi lọc theo status (bộ chọn phòng khi tạo Hợp đồng mới, Sơ đồ
    // phòng ở Dashboard), có thể dẫn tới gán nhầm 2 khách vào cùng 1 phòng cho tới lần đồng bộ kế tiếp
    // (khi hợp đồng có thay đổi, hoặc cron RefreshRoomStatusCommand chạy). Dùng để chặn ở RoomForm/
    // RoomController — KHÔNG chặn khi status vẫn giữ nguyên "Đang thuê"/"Đã đặt cọc".
    public function hasActiveContract(): bool
    {
        return $this->contracts()->where('status', Contract::STATUS_ACTIVE)->exists();
    }

    // Room dùng SoftDeletes — xoá chỉ set deleted_at, KHÔNG kích hoạt cascade FK thật ở CSDL. Chặn
    // xoá nếu còn BẤT KỲ hợp đồng nào (kể cả đã hết hạn/huỷ, không chỉ "Đang hiệu lực") tham chiếu
    // tới phòng này — hợp đồng cũ vẫn là dữ liệu lịch sử/hoá đơn thật, xoá phòng sẽ làm Contract.room_
    // id trỏ về 1 Room đã "biến mất" (mọi $contract->room sau đó trả về NULL do SoftDeletingScope,
    // làm hỏng hiển thị mã phòng trên hợp đồng/hoá đơn cũ) — xem CannotDeleteReferencedRecordException.
    protected static function booted(): void
    {
        static::deleting(function (Room $room) {
            if ($room->contracts()->exists()) {
                throw new CannotDeleteReferencedRecordException(
                    'Phòng này vẫn còn Hợp đồng (kể cả đã kết thúc) tham chiếu tới — không thể xoá để giữ nguyên lịch sử hợp đồng/hoá đơn.'
                );
            }
        });
    }
}
