<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Category\Entities\Category;
use Modules\Category\Traits\Categorizable;
use Modules\Comment\Entities\Comment;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Tags\HasTags;
use Guava\Calendar\Contracts\Resourceable;
use Guava\Calendar\ValueObjects\CalendarResource;

class Product extends Model implements HasMedia, Resourceable
{
    use HasFactory,
        Categorizable,
        InteractsWithMedia,
        HasTags,
        HasUlids;

    protected $fillable = [
        'name',
        'slug',
        'sku',
        'type',
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
        'wifi',
        'home_code',
        'lock_id',
        'lock_id_checkout',
        'address',
        'hotline',
        'full_booking_discount',
        'styles',
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
        'rating_score' => 'decimal:1',
        'is_in_stock'  => 'boolean',
        'is_activated' => 'boolean',
        'is_shipped'   => 'boolean',
        'is_trend'     => 'boolean',
        'room_config'       => 'array',
        'setting_video_room' => 'array',
        'badge'        => 'array',
    ];

    public function categories()
    {
        return $this->morphToMany(Category::class, 'categorizable');
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
        $category = $this->primary_category;
        return $category ? $category->id : null;
    }

    public function toCalendarResource(): CalendarResource
    {
        return CalendarResource::make($this->id)
            ->title($this->name)
            ->eventBackgroundColor('#10b981')
            ->eventTextColor('#ffffff');
    }
}
