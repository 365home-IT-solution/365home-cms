<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ngày lễ, Tết trong DANH MỤC dùng chung (xem migration create_holidays_table). partner_id NULL = dùng chung do
 * 365Home quản lý; có partner_id = của riêng đối tác. Không dùng BelongsToPartner vì dòng dùng chung phải
 * hiện cho mọi đối tác (scope đó sẽ lọc mất).
 */
class Holiday extends Model
{
    protected $fillable = ['partner_id', 'name', 'recurring', 'from_day', 'to_day', 'is_active', 'sort_order', 'note'];

    protected $casts = [
        'recurring'  => 'boolean',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function partner()
    {
        return $this->belongsTo(Partner::class);
    }

    /** Ngày lễ đối tác dùng được: dùng chung + của riêng đối tác. $partnerId null = chỉ dùng chung. */
    public function scopeUsableBy(Builder $query, ?string $partnerId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('partner_id')->when($partnerId, fn ($qq) => $qq->orWhere('partner_id', $partnerId)));
    }

    /** User xem được: super_admin chưa chọn đối tác thấy tất cả; còn lại dùng chung + đối tác đang làm việc. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->seesAllPartners() ? $query : $query->usableBy($user->partner_id ? (string) $user->partner_id : null);
    }

    /** Sửa/xoá được không: super_admin luôn được; đối tác chỉ ngày lễ của chính mình (dùng chung chỉ xem). */
    public function editableBy(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->partner_id !== null && (string) $this->partner_id === (string) $user->partner_id;
    }

    /** Dòng cho bộ giá / API: { id, name, recurring, from, to, ... }. */
    public function toRow(): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'recurring'  => (bool) $this->recurring,
            'from'       => $this->from_day,
            'to'         => $this->to_day,
            'is_active'  => (bool) $this->is_active,
            'shared'     => $this->partner_id === null,
            'partner_id' => $this->partner_id,
            'sort_order' => (int) $this->sort_order,
            'note'       => $this->note,
        ];
    }
}
