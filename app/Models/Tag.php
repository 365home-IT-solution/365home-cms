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
