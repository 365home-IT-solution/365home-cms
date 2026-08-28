<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\BelongsToActiveBranchCategories;
use App\Models\Concerns\BelongsToPartner;
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
        'address',
        'latitude',
        'longitude',
        'map_url',
        'hotline',
        'full_booking_discount',
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

    protected $casts = [
        'price'        => 'decimal:2',
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
    public function scopeActiveBranch($query)
    {
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

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function roomTimeSlots()
    {
        return $this->hasMany(RoomTimeSlot::class, 'room_id');
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
        foreach (['thumb' => 240, 'card' => 480, 'wide' => 1080, 'full' => 1440] as $name => $size) {
            $this->addMediaConversion($name)
                ->fit(Fit::Max, $size, $size)
                ->format('avif')
                ->quality(72)
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
