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
// minihouse_room_details) bằng accessor/mutator (getXAttribute/setXAttribute) chuẩn của Eloquent bên
// dưới; field đổi tên (code<->name, area<->room_area_sqm, note<->description) forward thẳng sang
// cột thật của Product.
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

    protected $fillable = [
        'building_id', 'code', 'floor', 'position_row', 'position_col', 'area', 'price', 'status', 'note', 'photos',
        // Cột THẬT có sẵn trên products (không cần accessor/mutator riêng, dùng thẳng như Product) —
        // PHẢI khai báo lại ở đây vì $fillable của Room hẹp hơn Product, không tự "kế thừa" được field
        // nào ngoài danh sách này cho mass-assignment (Room::create()/update() sẽ ÂM THẦM bỏ qua các
        // field không có mặt ở đây, không lỗi, không warning).
        'slug', 'address', 'latitude', 'longitude', 'map_url', 'hotline', 'setting_video_room',
    ];

    protected $appends = ['code', 'area', 'note', 'floor', 'position_row', 'position_col', 'status', 'photos'];

    /** Giá trị vừa set() nhưng chưa flush xuống RoomDetail (flush ở sự kiện saved(), xem booted()). */
    private array $pendingDetail = [];

    // Dùng ĐÚNG quy ước accessor/mutator chuẩn của Eloquent (getXAttribute/setXAttribute) — KHÔNG
    // override getAttribute()/setAttribute() ở tầng thấp như bản trước: Eloquent tự gọi thẳng
    // get{X}Attribute() ở nhiều chỗ khác NGOÀI property access thông thường (VD
    // attributesToArray()/toArray() xử lý $appends bằng cách gọi trực tiếp mutateAttribute() ->
    // $this->getCodeAttribute(), KHÔNG đi qua getAttribute() — đã tự kiểm chứng: Filament
    // EditRecord::fillForm() gọi $record->attributesToArray() lúc tải trang Sửa, ném lỗi "Call to
    // undefined method getCodeAttribute()" vì bản override cũ chỉ bắt được property access, không
    // bắt được đường gọi trực tiếp này).
    public function getCodeAttribute(): ?string
    {
        return $this->attributes['name'] ?? null;
    }

    public function setCodeAttribute(?string $value): void
    {
        $this->attributes['name'] = $value;
    }

    // 'float' như Room cũ (không hiện ép buộc ",00" giả như decimal:2 của Product.room_area_sqm).
    public function getAreaAttribute(): ?float
    {
        return isset($this->attributes['room_area_sqm']) ? (float) $this->attributes['room_area_sqm'] : null;
    }

    public function setAreaAttribute(?float $value): void
    {
        $this->attributes['room_area_sqm'] = $value;
    }

    public function getNoteAttribute(): ?string
    {
        return $this->attributes['description'] ?? null;
    }

    public function setNoteAttribute(?string $value): void
    {
        $this->attributes['description'] = $value;
    }

    // floor/position_row/position_col/status/photos KHÔNG tồn tại trên products — uỷ quyền qua
    // RoomDetail (bảng minihouse_room_details), đệm ở $pendingDetail cho tới khi flush ở saved().
    public function getFloorAttribute(): ?int
    {
        return $this->detailValue('floor');
    }

    public function setFloorAttribute($value): void
    {
        $this->pendingDetail['floor'] = $value;
    }

    public function getPositionRowAttribute(): ?int
    {
        return $this->detailValue('position_row');
    }

    public function setPositionRowAttribute($value): void
    {
        $this->pendingDetail['position_row'] = $value;
    }

    public function getPositionColAttribute(): ?int
    {
        return $this->detailValue('position_col');
    }

    public function setPositionColAttribute($value): void
    {
        $this->pendingDetail['position_col'] = $value;
    }

    public function getStatusAttribute(): string
    {
        return $this->detailValue('status') ?? self::STATUS_EMPTY;
    }

    public function setStatusAttribute($value): void
    {
        $this->pendingDetail['status'] = $value;
    }

    // Ảnh nhập qua form (MediaManagerInput, collection "Ảnh bìa" rồi "Thư viện" — giống Home) là NGUỒN
    // CHÍNH, trả về URL đầy đủ, ảnh bìa đứng đầu để mọi nơi đang lấy photos[0] làm ảnh đại diện vẫn
    // đúng. Phòng chưa có ảnh nào trong thư viện thì rơi về danh sách đường dẫn cũ lưu ở
    // minihouse_room_details.photos (dữ liệu tạo trước khi đổi bộ chọn ảnh).
    public function getPhotosAttribute(): ?array
    {
        if ($this->exists) {
            $urls = $this->getMedia('Ảnh bìa')
                ->concat($this->getMedia('Thư viện'))
                ->map(fn ($media) => $media->getUrl())
                ->values()
                ->all();

            if ($urls !== []) {
                return $urls;
            }
        }

        return $this->detailValue('photos');
    }

    public function setPhotosAttribute($value): void
    {
        $this->pendingDetail['photos'] = $value;
    }

    private function detailValue(string $key)
    {
        if (array_key_exists($key, $this->pendingDetail)) {
            return $this->pendingDetail[$key];
        }

        return $this->detail?->{$key};
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

    // Public — dùng chung với RoomForm (Filament) để tự gợi ý slug ngay khi nhân viên gõ Mã/Tên
    // phòng, không đợi tới lúc lưu mới biết slug là gì.
    public static function generateUniqueSlug(string $base): string
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

    // QUAY LẠI dùng đúng hệ Amenity/minihouse_room_amenity RIÊNG của MiniHouse như trước khi gộp
    // bảng — từng đổi thử sang hệ RoomAmenity chung của Home (Modules\Product), nhưng
    // AmenityController (API danh mục tiện ích /api/admin/minihouse/amenities) VẪN quản lý đúng
    // Amenity/minihouse_amenities cũ, không đổi theo — đổi 1 mình quan hệ này sẽ khiến amenity_ids
    // trả về từ danh mục KHÔNG CÒN hợp lệ khi gán cho phòng nữa (validate theo bảng khác). Giữ
    // nguyên hệ cũ mới đúng "hoạt động như cũ" — chỉ cần nới kiểu cột
    // minihouse_room_amenity.room_id sang char(36) cho khớp id mới của Room (xem migration
    // widen_room_id_on_minihouse_room_amenity).
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'minihouse_room_amenity');
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

    // Dùng cho trang tìm phòng công khai (Api\Minihouse\Public\*) — status uỷ quyền qua RoomDetail
    // (xem detailValue() ở trên) nên không lọc trực tiếp cột "status" được, phải whereHas('detail').
    public function scopeAvailable(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->whereHas('detail', fn ($q) => $q->where('status', self::STATUS_EMPTY));
    }
}
