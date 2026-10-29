<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\BelongsToActiveBranchCategories;
use App\Models\Concerns\BelongsToPartner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;
use Modules\Category\Traits\Categorizable;
use Modules\Comment\Entities\Comment;
use Modules\Employee\Entities\Employee;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Tags\HasTags;
use Guava\Calendar\Contracts\Resourceable;
use Guava\Calendar\ValueObjects\CalendarResource;

class Product extends Model implements HasMedia, Resourceable
{
    use HasFactory,
        Categorizable,
        InteractsWithMedia,
        HasTags,
        HasUlids,
        BelongsToPartner,
        BelongsToActiveBranchCategories;

    protected $fillable = [
        'partner_id',
        'name',
        'slug',
        'sku',
        'type',
        'bed_type',
        'room_area_sqm',
        'description',
        'short_description',
        'price',
        'discount',
        'vat',
        'weight',
        'length',
        'width',
        'height',
        'is_in_stock',
        'is_activated',
        'is_shipped',
        'is_trend',
        'housekeeping_status',
        'wifi',
        'home_code',
        'lock_id',
        'lock_id_checkout',
        'has_manual_lock',
        'unlock_both_locks',
        'emergency_locked_at',
        'emergency_locked_by',
        'emergency_lock_reason',
        'address',
        'latitude',
        'longitude',
        'map_url',
        'hotline',
        'full_booking_discount',
        'full_day_price',
        'bulk_discount_rules',
        'styles',
        'nights',
        'default_checkin',
        'default_checkout',
        'deposit_1_night',
        'deposit_multi_night',
        'deposit_min_nights',
        'room_config',
        'setting_video_room',
        'room_type_id',
        'thumbnail_color',
        'price_unit',
        'rating_score',
        'badge',
    ];

    /** Lúc đối tác đăng phòng mới: đối tác muốn phòng bật hay tắt (áp sau khi duyệt) — không phải cột DB, chỉ mang từ creating sang created. */
    public ?bool $approvalRequestedActive = null;

    protected $casts = [
        'price'        => 'decimal:2',
        'full_day_price' => 'decimal:2',
        'discount'     => 'decimal:2',
        'vat'          => 'decimal:2',
        'weight'       => 'decimal:2',
        'room_area_sqm' => 'decimal:2',
        'rating_score' => 'decimal:1',
        'is_in_stock'     => 'boolean',
        'is_activated'    => 'boolean',
        'is_shipped'      => 'boolean',
        'is_trend'        => 'boolean',
        'has_manual_lock' => 'boolean',
        'unlock_both_locks' => 'boolean',
        'emergency_locked_at' => 'datetime',
        'nights'          => 'boolean',
        'room_config'          => 'array',
        'bulk_discount_rules'  => 'array',
        'setting_video_room'   => 'array',
        'badge'        => 'array',
    ];

    public function categories()
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }

    // Loại phòng/tìm kiếm chỉ nên hiện phòng thuộc chi nhánh (category gốc, category_type=product)
    // đang active (status=true) — chi nhánh tạm ngưng/ẩn thì phòng của nó cũng phải biến mất khỏi
    // mọi danh sách/tìm kiếm, bất kể lọc theo tỉnh/ward/chi nhánh cụ thể hay tìm toàn quốc. Chỉ
    // LOẠI (whereDoesntHave) phòng thuộc chi nhánh KHÔNG active — phòng chưa gắn chi nhánh nào vẫn
    // giữ nguyên hiển thị như trước (không phải điều được yêu cầu ở đây).
    //
    // Cùng chỗ này ẩn luôn phòng của đối tác đang bị TẠM NGƯNG BÁN do ký quỹ (xem
    // App\Services\EscrowService) — cùng ý nghĩa "không bán online nữa", và mọi danh sách/tìm kiếm
    // công khai (API lẫn web) đều đã đi qua scope này.
    public function scopeActiveBranch($query)
    {
        $suspendedPartnerIds = \App\Services\EscrowService::suspendedPartnerIds();

        if ($suspendedPartnerIds !== []) {
            $query->where(fn ($q) => $q->whereNull($q->qualifyColumn('partner_id'))->orWhereNotIn($q->qualifyColumn('partner_id'), $suspendedPartnerIds));
        }

        $inactiveCatIds = Category::inactiveBranchCategoryIds();

        if ($inactiveCatIds->isEmpty()) {
            return $query;
        }

        return $query->whereDoesntHave('categories', fn ($q) => $q->whereIn('categories.id', $inactiveCatIds));
    }

    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($product) {
            $product->comments()->delete();
        });
    }

    // Dùng booted() (thay vì nhét vào boot() ở trên) CỐ Ý — Modules\Minihouse\App\Models\Room
    // extends Product để dùng chung bảng products (đồng nhất kiến trúc dữ liệu), nhưng phải LOẠI TRỪ
    // đúng scope này cho riêng Room (Room hiển thị NGƯỢC LẠI — CHỈ phòng MiniHouse, không phải loại
    // trừ nó). boot() luôn được Room kế thừa nguyên vẹn (Room không override boot()) nên vẫn giữ
    // được hành vi xoá comment ở trên; còn booted() thì Room ghi đè HẲN bằng booted() riêng của nó
    // (không gọi parent::booted()) nên scope dưới đây KHÔNG bao giờ được đăng ký cho Room — cách duy
    // nhất để 1 scope "biến mất" hoàn toàn với 1 model con, vì gọi withoutGlobalScope() từ TRONG 1
    // scope khác không có tác dụng (thứ tự áp dụng scope không đảm bảo scope bị gỡ chạy trước scope
    // cần gỡ — đã tự kiểm chứng: Room::count() trả về 0 nếu làm theo cách đó, vì điều kiện "loại trừ
    // minihouse" và "chỉ lấy minihouse" bị AND lại thành mâu thuẫn).
    protected static function booted(): void
    {
        // Phòng của module MiniHouse (cho thuê dài hạn theo hợp đồng, room_type_id = RoomType
        // "minihouse") mượn bảng products để đồng nhất kiến trúc với Home, nhưng TUYỆT ĐỐI không
        // được lọt vào bất kỳ luồng đặt phòng ngắn hạn nào (tìm kiếm, bảng giá, khuyến mãi, dashboard
        // room cards...) — không có 1 lớp truy vấn chung nào tất cả các luồng đó cùng gọi qua (đã rà
        // soát: RoomSearchService, Livewire Products.php, HomeController, RoomController,
        // BookingController, RoomTypeController, RoomCardsService, PriceBoardSyncService,
        // ProductResource đều tự Product:: riêng), nên chặn tập trung ở ĐÚNG 1 chỗ bằng Global Scope
        // — mọi nơi trên tự động được loại trừ mà không cần sửa từng nơi. Phía MiniHouse tự truy vấn
        // phòng của mình phải chủ động withoutGlobalScope('exclude_minihouse').
        static::addGlobalScope('exclude_minihouse', function (Builder $query) {
            $query->whereDoesntHave('roomType', fn ($q) => $q->where('slug', RoomType::MINIHOUSE_SLUG));
        });

        // Xoá chi nhánh (Category xoá cứng, không cascade) để lại phòng mồ côi — không còn gắn danh
        // mục nào. Web đã không hiện được chúng, nhưng API (app) từng vẫn trả ra. Chặn tập trung ở
        // đây để MỌI API/trang công khai đi qua Product:: đều khớp web: chỉ phòng còn gắn chi nhánh
        // mới hiển thị — áp dụng cả trong admin panel Filament (danh sách, dropdown chọn phòng).
        // Phòng MiniHouse (Room) không bị ảnh hưởng vì Room ghi đè booted() (xem ghi chú phía trên).
        static::addGlobalScope('has_branch', function (Builder $query) {
            $query->whereHas('categories');
        });

        // ── Duyệt trước khi đăng (App\Services\ApprovalService) ───────────────────────────────────────────────
        // Đối tác đăng phòng MỚI → pending: phòng không hiện cho khách, không đặt được (mọi nơi đều đọc is_activated) cho tới khi 365home duyệt.
        static::creating(function (Product $product) {
            if (app(\App\Services\ApprovalService::class)->guards()) {
                $product->approvalRequestedActive = $product->is_activated === null ? true : (bool) $product->is_activated;
                $product->setAttribute('approval_status', 'pending');
                $product->setAttribute('is_activated', false);
            }
        });

        static::created(function (Product $product) {
            if (($product->approval_status ?? 'approved') === 'pending' && app(\App\Services\ApprovalService::class)->guards()) {
                app(\App\Services\ApprovalService::class)->submitRoomCreate($product, $product->approvalRequestedActive ?? true, auth()->user());
            }
        });

        // Phòng bị xoá: yêu cầu duyệt đang chờ của phòng tự đóng, không treo mãi.
        static::deleted(fn (Product $product) => app(\App\Services\ApprovalService::class)->closeForDeletedSubject('product', $product->id));

        // Phòng chưa được duyệt (pending / rejected) không bao giờ bật được, dù bật bằng đường nào (API, Filament, nhập liệu...).
        static::saving(function (Product $product) {
            if (($product->approval_status ?? 'approved') !== 'approved' && $product->is_activated) {
                $product->setAttribute('is_activated', false);
            }
        });

        // Đối tác sửa nội dung / giá của phòng ĐÃ duyệt: giữ nguyên bản đang chạy, bản sửa vào yêu cầu "Sửa phòng" chờ duyệt (các cột khác — bật/tắt, vệ sinh, khoá... — lưu như thường).
        static::updating(function (Product $product) {
            $gate = app(\App\Services\ApprovalService::class);

            if (($product->approval_status ?? 'approved') !== 'approved' || ! $gate->guards()) {
                return;
            }

            $guarded = array_keys(\App\Services\ApprovalService::PRODUCT_CONTENT_FIELDS + \App\Services\ApprovalService::PRODUCT_PRICE_FIELDS);
            $changes = [];

            foreach (array_keys($product->getDirty()) as $field) {
                if (! in_array($field, $guarded, true)) {
                    continue;
                }

                $changes[$field] = ['old' => $product->getOriginal($field), 'new' => $product->getAttribute($field)];
                $product->setAttribute($field, $product->getOriginal($field));
            }

            if ($changes) {
                $gate->mergeRoomChanges($product, $changes, [], auth()->user());
            }
        });

        // Đối tác Homestay thêm/mở bán phòng → mức ký quỹ gợi ý có thể tăng: báo đối tác nạp thêm (không khoá bán ngay) — xem EscrowService::syncMinWithRooms().
        static::saved(function (Product $product) {
            if (! $product->partner_id || ! ($product->wasRecentlyCreated || $product->wasChanged('is_activated'))) {
                return;
            }

            try {
                app(\App\Services\EscrowService::class)->syncMinWithRooms((string) $product->partner_id);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * "Giảm giá đặt trọn" HIỆU LỰC dùng để TÍNH TIỀN và hiển thị (chuỗi "10%" hoặc số tiền, như cột full_booking_discount).
     *
     * Phòng theo khung giờ có "Giá cả ngày" (full_day_price): khách chọn đủ mọi khung giờ trong ngày thì tính đúng giá đó và thay cho "Giảm giá đặt trọn". Giá đó được quy ra
     * PHẦN TRĂM trên tổng giá các khung hiện tại (làm tròn lên ở 8 chữ số thập phân để không bao giờ thiếu 1đ), nên mọi nơi đã hiểu chuỗi "x%" (API, web, JS đặt phòng, form đơn)
     * ra cùng một giá, và đổi giá khung thì giá cả ngày vẫn đúng, không bị cũ. Chưa đặt giá cả ngày, hoặc giá cả ngày không thấp hơn tổng các khung (không có gì để giảm), hoặc có khung
     * chưa có giá: dùng đúng cột full_booking_discount như cũ khi chưa đặt giá cả ngày, ngược lại không giảm.
     */
    public function getEffectiveFullBookingDiscountAttribute(): ?string
    {
        $price = $this->full_day_price;

        if ((int) ($this->styles ?? 1) !== 1 || $price === null || (float) $price <= 0) {
            return $this->getRawOriginal('full_booking_discount') !== null ? $this->full_booking_discount : null;
        }

        $slots = $this->roomTimeSlots;
        $base  = $slots->filter(fn ($s) => is_null($s->date));

        if ($base->isEmpty() || $base->contains(fn ($s) => $s->price === null)) {
            return null;
        }

        $sum = (float) $base->sum(fn ($s) => (float) $s->price);

        if ($sum <= 0 || (float) $price >= $sum) {
            return null;
        }

        // Làm tròn LÊN để phần giảm (int, cắt bỏ phần lẻ) không bao giờ thấp hơn giá trị đúng dù một đồng.
        $percent = ceil((($sum - (float) $price) / $sum) * 100 * 1e8) / 1e8;

        return rtrim(rtrim(number_format($percent, 8, '.', ''), '0'), '.') . '%';
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function roomTimeSlots()
    {
        return $this->hasMany(RoomTimeSlot::class, 'room_id');
    }

    public function priceBoardItems()
    {
        return $this->hasMany(PriceBoardItem::class);
    }

    public function priceBoards()
    {
        return $this->belongsToMany(PriceBoard::class, 'price_board_items', 'product_id', 'price_board_id');
    }

    public function defaultPriceBoard()
    {
        return $this->priceBoards()->where('is_default', true);
    }

    public function amenities()
    {
        return $this->belongsToMany(RoomAmenity::class, 'room_amenity_assigns', 'room_id', 'amenity_id')
            ->where('status', true)
            ->orderBy('amenity_type')
            ->orderBy('sort_order');
    }

    public function amenityAssigns()
    {
        return $this->hasMany(RoomAmenityAssign::class, 'room_id');
    }

    public function roomImages()
    {
        return $this->hasMany(RoomImage::class, 'room_id')->orderBy('sort_order');
    }

    public function mainImage()
    {
        return $this->hasOne(RoomImage::class, 'room_id')->where('type', 'main')->orderBy('sort_order');
    }

    public function galleryImages()
    {
        return $this->hasMany(RoomImage::class, 'room_id')->where('type', 'gallery')->orderBy('sort_order');
    }

    public function services()
    {
        return $this->hasMany(RoomService::class, 'product_id')->orderBy('sort_order');
    }

    public function specials()
    {
        return $this->hasMany(RoomSpecial::class, 'product_id')->orderBy('sort_order');
    }

    public function orderItems()
    {
        return $this->hasMany(\Modules\Payment\Entities\OrderItem::class, 'product_id');
    }

    public function cleaningLogs()
    {
        return $this->hasMany(RoomCleaningLog::class, 'product_id')->latest('marked_for_cleaning_at');
    }

    // Lượt dọn đang chờ xác nhận (nếu phòng đang ở trạng thái 'cleaning') — dùng để cập nhật
    // đúng dòng log khi nhân viên xác nhận đã dọn xong, thay vì tạo dòng mới mỗi lần.
    public function pendingCleaningLog()
    {
        return $this->cleaningLogs()->whereNull('cleaned_at')->first();
    }

    public function isBeingCleaned(): bool
    {
        return $this->housekeeping_status === 'cleaning';
    }

    public function markForCleaning(?int $orderItemId = null): void
    {
        if ($this->housekeeping_status !== 'available') {
            return;
        }

        $this->update(['housekeeping_status' => 'cleaning']);

        $this->cleaningLogs()->create([
            'order_item_id'           => $orderItemId,
            'marked_for_cleaning_at'  => now(),
        ]);
    }

    // $employee null khi super_admin/chủ đối tác tự xác nhận — 2 vai trò này không có hồ sơ Employee
    // gắn với đối tác nào (xem ProductController::confirmCleaning()/RoomCleaningAction — chỉ cho phép
    // null khi user isSuperAdmin()/isPartnerOwner(), nhân viên thường vẫn bắt buộc phải có Employee).
    public function confirmCleaned(?Employee $employee, ?string $note = null): void
    {
        $this->update(['housekeeping_status' => 'available']);

        $log = $this->pendingCleaningLog();

        if ($log) {
            $log->update([
                'employee_id' => $employee?->id,
                'cleaned_at'  => now(),
                'note'        => $note,
            ]);

            return;
        }

        // Không có log tự động (vd super_admin/nhân viên tự bấm dọn thủ công trước đó) — vẫn ghi
        // lại 1 dòng để có audit trail đầy đủ.
        $this->cleaningLogs()->create([
            'employee_id'            => $employee?->id,
            'marked_for_cleaning_at' => now(),
            'cleaned_at'             => now(),
            'note'                   => $note,
        ]);
    }

    public function manualLockPasswords()
    {
        return $this->belongsToMany(
            ManualLockPassword::class,
            'manual_lock_password_product',
            'product_id',
            'manual_lock_password_id'
        );
    }

    public function ratings()
    {
        return $this->hasMany(\App\Models\RoomRating::class, 'room_id');
    }

    public function additionalServices()
    {
        return $this->belongsToMany(
            \Modules\BladeThemeV1\App\Models\AdditionService::class,
            'room_additional_service_assigns',
            'room_id',
            'additional_service_id'
        );
    }

    public function getMediaFileAttribute(): ?string
    {
        $media = $this->getFirstMedia('Ảnh bìa');
        if (!$media) {
            $media = $this->getFirstMedia('Thư viện');
        }
        if (!$media) {
            $media = $this->getFirstMedia();
        }
        return $media ? "{$media->id}/{$media->file_name}" : null;
    }

    public function getBranchCategoryIdAttribute()
    {
        return $this->categories()->where('category_type', 'product')->value('categories.id');
    }

    // 4 preset kích thước cho ảnh phòng (thumbnail card, chi tiết phòng...) — xem
    // docs/be-image-thumbnails.md. Áp dụng cho mọi collection ảnh (Ảnh bìa, Ảnh chính, Thư viện).
    // Bản gốc đã bị ResizeOversizedMedia hạ xuống ≤1440px trước khi conversion này chạy (cùng
    // event MediaHasBeenAddedEvent, xem EventServiceProvider), nên preset "full" thực chất chỉ là
    // đổi định dạng/nén lại bản gốc, không cắt thêm kích thước.
    public function registerMediaConversions(?Media $media = null): void
    {
        // thumb/card (thẻ phòng nhỏ trên trang chủ/tìm kiếm) nén mạnh hơn: Lighthouse "Improve image
        // delivery" chấm bản card 480px quality 72 (~30KB) dư ~15KB. wide/full (xem ảnh lớn ở trang
        // chi tiết) giữ 72 để không thấy rõ vỡ nét.
        foreach (['thumb' => 240, 'card' => 480, 'wide' => 1080, 'full' => 1440] as $name => $size) {
            $this->addMediaConversion($name)
                ->fit(Fit::Max, $size, $size)
                ->format('avif')
                ->quality($size <= 480 ? 60 : 72)
                ->nonQueued();
        }
    }

    public function toCalendarResource(): CalendarResource
    {
        return CalendarResource::make($this->id)
            ->title($this->name)
            ->eventBackgroundColor('#10b981')
            ->eventTextColor('#ffffff');
    }
}
