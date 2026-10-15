<?php

namespace Modules\Product\App\Models;

use App\Models\Concerns\LogsAuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Bảng giá mẫu — bộ khung giờ + giá có sẵn để áp xuống phòng (xem PriceTemplateController).
 * partner_id NULL = mẫu hệ thống dùng chung; source_template_id = mẫu gốc khi đây là bản riêng của
 * đối tác tách ra lúc sửa mẫu hệ thống.
 */
class PriceTemplate extends Model
{
    use LogsAuditTrail;

    protected $fillable = [
        'partner_id',
        'source_template_id',
        'name',
        'note',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function slots()
    {
        return $this->hasMany(PriceTemplateSlot::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sourceTemplate()
    {
        return $this->belongsTo(self::class, 'source_template_id');
    }

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
