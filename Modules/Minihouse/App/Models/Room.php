<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Modules\Minihouse\App\Exceptions\CannotDeleteReferencedRecordException;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;
use Modules\Minihouse\App\Models\Concerns\ScopedToActiveBuildingId;
use Modules\Minihouse\App\Support\HomestayBridge;
use Modules\Product\App\Models\Product;
use Modules\Product\App\Models\RoomType;

// "Phòng" MiniHouse (cho thuê dài hạn theo hợp đồng) — VẬT LÝ là 1 dòng trong bảng `products` của
// Home (đồng nhất kiến trúc dữ liệu, xem plan gộp MiniHouse-Homestay), gắn `room_type_id` =
// RoomType::MINIHOUSE_SLUG để Product::booted() tự loại khỏi mọi luồng đặt phòng ngắn hạn.
//
// GIỮ NGUYÊN 100% API cũ ($room->code/$room->area/$room->status/$room->floor/$room->photos,
// Room::create([...]), Room::STATUS_*...) để ~80 file đang dùng class này (Filament Resource, API
// Controller, Observer, Seeder, test) KHÔNG PHẢI SỬA GÌ — field không tồn tại trên products
// (floor/position_row/position_col/status/photos) được uỷ quyền qua RoomDetail (bảng
// minihouse_room_details) trong getAttribute()/setAttribute() bên dưới; field đổi tên
// (code<->name, area<->room_area_sqm, note<->description) forward thẳng sang cột thật của Product.
// building_id LÀ CỘT THẬT trên products (xem migration add_minihouse_building_id_to_products) nên
// không cần uỷ quyền — 4 trait ScopedToActiveBuilding* lọc SQL trực tiếp vẫn hoạt động y hệt.
class Room extends Product
{
    use SoftDeletes;
    use ScopedToActiveBuildingId;
    use LogsMinihouseActivity;

    public const STATUS_EMPTY    = 'trong';
    public const STATUS_RESERVED = 'dat_coc';
    public const STATUS_RENTED   = 'dang_thue';
    public const STATUS_REPAIR   = 'bao_tri';

    // Product KHÔNG khai báo $table tường minh (dựa vào quy ước Eloquent tự suy tên bảng từ TÊN
    // CLASS THẬT LÚC CHẠY) — Room là class con nên PHẢI khai báo lại rõ ràng, nếu không Eloquent sẽ
    // tự suy nhầm thành bảng "rooms" (số nhiều của "Room") thay vì "products".
    protected $table = 'products';

    // key hiển thị cũ => cột thật trên products
    private const RENAMED = ['code' => 'name', 'area' => 'room_area_sqm', 'note' => 'description'];

    // key hiển thị cũ không tồn tại trên products => uỷ quyền qua RoomDetail
    private const DETAIL_FIELDS = ['floor', 'position_row', 'position_col', 'status', 'photos'];

    protected $fillable = ['building_id', 'code', 'floor', 'position_row', 'position_col', 'area', 'price', 'status', 'note', 'photos'];

    protected $appends = ['code', 'area', 'note', 'floor', 'position_row', 'position_col', 'status', 'photos'];

    /** Giá trị vừa set() nhưng chưa flush xuống RoomDetail (flush ở sự kiện saved(), xem booted()). */
    private array $pendingDetail = [];

    public function getAttribute($key)
    {
        if (isset(self::RENAMED[$key])) {
            $value = parent::getAttribute(self::RENAMED[$key]);

            // area vốn cast 'float' bên Room cũ (không hiện ép buộc ",00" giả như decimal:2 của
            // Product.room_area_sqm) — ép lại kiểu ở đây để giữ đúng hành vi hiển thị cũ.
            return $key === 'area' && $value !== null ? (float) $value : $value;
        }

        if (in_array($key, self::DETAIL_FIELDS, true)) {
            if (array_key_exists($key, $this->pendingDetail)) {
                return $this->pendingDetail[$key];
            }

            return $this->detail?->{$key} ?? ($key === 'status' ? self::STATUS_EMPTY : null);
        }

        return parent::getAttribute($key);
    }

    public function setAttribute($key, $value)
    {
        if (isset(self::RENAMED[$key])) {
            return parent::setAttribute(self::RENAMED[$key], $value);
        }

        if (in_array($key, self::DETAIL_FIELDS, true)) {
            $this->pendingDetail[$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function detail(): HasOne
    {
        return $this->hasOne(RoomDetail::class, 'product_id');
    }

    protected static function booted(): void
    {
        // KHÔNG gọi parent::booted() — Product::booted() đăng ký scope 'exclude_minihouse' (loại
        // phòng MiniHouse khỏi luồng Home), Room cần NGƯỢC LẠI (chỉ lấy đúng phòng MiniHouse) nên để
        // scope đó không bao giờ được đăng ký cho Room ngay từ đầu, thay vì cố gỡ nó sau (gọi
        // withoutGlobalScope() từ TRONG 1 scope khác không có tác dụng — đã tự kiểm chứng
        // Room::count() ra 0 nếu làm vậy, xem comment ở Product::booted()).
        static::addGlobalScope('minihouse_only', function (Builder $query) {
            $query->whereHas('roomType', fn ($q) => $q->where('slug', RoomType::MINIHOUSE_SLUG));
        });

        static::creating(function (Room $room) {
            $room->partner_id   ??= HomestayBridge::PARTNER_ID;
            $room->room_type_id ??= RoomType::where('slug', RoomType::MINIHOUSE_SLUG)->value('id');
            $room->styles       ??= 2;
            $room->is_in_stock  ??= true;

            if (! $room->name && $room->getAttribute('name')) {
                // đã set qua RENAMED (code) — không cần gì thêm
            }

            if (! $room->slug) {
                $room->slug = self::generateUniqueSlug($room->name ?: 'phong');
            }
        });

        // is_activated theo status ĐÚNG như Room cũ: chỉ "Bảo trì" mới coi là ngừng hoạt động.
        static::saving(function (Room $room) {
            $status = $room->pendingDetail['status'] ?? $room->detail?->status ?? self::STATUS_EMPTY;
            $room->is_activated = $status !== self::STATUS_REPAIR;
        });

        static::saved(function (Room $room) {
            if (! empty($room->pendingDetail)) {
                RoomDetail::updateOrCreate(['product_id' => $room->id], $room->pendingDetail);
                $room->pendingDetail = [];
                $room->unsetRelation('detail');
            }
        });

        // Room dùng SoftDeletes (qua products.deleted_at) — xoá chỉ set deleted_at, KHÔNG kích hoạt
        // cascade FK thật ở CSDL. Chặn xoá nếu còn BẤT KỲ hợp đồng nào (kể cả đã hết hạn/huỷ) tham
        // chiếu tới phòng này — xem CannotDeleteReferencedRecordException (giữ nguyên lý do như bản
        // gốc).
        static::deleting(function (Room $room) {
            if ($room->contracts()->exists()) {
                throw new CannotDeleteReferencedRecordException(
                    'Phòng này vẫn còn Hợp đồng (kể cả đã kết thúc) tham chiếu tới — không thể xoá để giữ nguyên lịch sử hợp đồng/hoá đơn.'
                );
            }
        });
    }

    private static function generateUniqueSlug(string $base): string
    {
        $slug = 'mh-' . Str::slug($base);
        $i    = 1;

        while (Product::withoutGlobalScope('exclude_minihouse')->where('slug', $slug)->exists()) {
            $slug = 'mh-' . Str::slug($base) . '-' . (++$i);
        }

        return $slug;
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class);
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'room_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class, 'room_id');
    }

    // Ảnh 360° chụp bên TRONG phòng này (nếu có) — xem PanoramaScene.
    public function panoramaScenes(): HasMany
    {
        return $this->hasMany(PanoramaScene::class, 'room_id')->orderBy('sort_order');
    }

    // Dùng hệ Amenity CHUNG của Home (Modules\Product\App\Models\RoomAmenity, bảng room_amenities +
    // pivot product_amenity) — KHÔNG PHẢI Modules\Minihouse\App\Models\Amenity/minihouse_room_amenity
    // cũ nữa: pivot cũ có cột room_id kiểu bigint trỏ minihouse_rooms, không tương thích với id
    // kiểu ULID mới của Room (đã đổi hẳn sang products). Giai đoạn 2 (data migration) đã chuyển toàn
    // bộ minihouse_amenities/minihouse_room_amenity cũ sang RoomAmenity/product_amenity rồi.
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(\Modules\Product\App\Models\RoomAmenity::class, 'product_amenity', 'product_id', 'amenity_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(RoomAsset::class, 'room_id');
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
}
