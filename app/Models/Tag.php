<?php

namespace App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Tags\Tag as BaseTag;

class Tag extends BaseTag
{
    use LogsAuditTrail;

    public array $translatable = ['name', 'slug', 'image'];

    public $guarded = [];

    // Tạo tiện ích không kèm ảnh → dùng ảnh mặc định, để không có tiện ích nào "thiếu ảnh".
    protected static function booted(): void
    {
        static::creating(function (self $tag) {
            if (! app(\App\Services\DefaultCatalogImage::class)->tagHasImage($tag)
                && blank($tag->getTranslation('image', app()->getLocale(), false))) {
                $tag->image = app(\App\Services\DefaultCatalogImage::class)->amenityPath();
            }
        });
    }

    // partner_id NULL = tiện ích mẫu hệ thống (đối tác chỉ chọn); có partner_id = tiện ích đối tác
    // đó tự thêm, chỉ đối tác đó thấy/gán được.
    public function isSystem(): bool
    {
        return $this->partner_id === null;
    }

    public function scopeUsableBy(Builder $query, ?string $partnerId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('partner_id')
            ->when($partnerId, fn (Builder $q2) => $q2->orWhere('partner_id', $partnerId)));
    }
}
