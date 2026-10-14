<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'partner_id', // NULL = khung giờ mẫu hệ thống (đối tác chỉ chọn, không sửa/xoá)
        'start_time',
        'end_time',
        'label',
        'over_night',
        'type', // 'time' | 'date'
    ];

    protected $casts = [
        'over_night' => 'boolean',
    ];

    public function roomTimeSlots()
    {
        return $this->hasMany(RoomTimeSlot::class);
    }

    public function isSystem(): bool
    {
        return $this->partner_id === null;
    }

    /** Khung giờ đối tác $partnerId được dùng: mẫu hệ thống + khung giờ riêng của đối tác đó. */
    public function scopeUsableBy(Builder $query, ?string $partnerId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('partner_id')
            ->when($partnerId, fn (Builder $q2) => $q2->orWhere('partner_id', $partnerId)));
    }

    /** Dropdown ở Filament: đối tác đang đăng nhập chỉ thấy mẫu hệ thống + khung giờ của mình. */
    public function scopeForCurrentAdmin(Builder $query): Builder
    {
        $user = auth()->user();

        if (! $user instanceof \App\Models\User || $user->seesAllPartners()) {
            return $query;
        }

        return $query->usableBy($user->partner_id);
    }
}
