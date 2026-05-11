<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Promotion\App\Models\Promotion;

class RoomTimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'timeslot_id',
        'price',
        'checkin',
        'checkout',
        'status',
        'over_night',
        'settings',
    ];

    protected $casts = [
        'date'     => 'date',
        'settings' => 'array', // ← Fix: đổi từ 'setting' → 'settings', json → array
    ];

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class, 'timeslot_id');
    }

    public function promotions()
    {
        return $this->belongsToMany(
            Promotion::class,
            'promotion_room_time_slot',
            'room_time_slot_id',
            'promotion_id'
        );
    }

    public function room()
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    public function coupons()
    {
        return $this->belongsToMany(
            \Modules\Promotion\App\Models\Coupon::class,
            'coupon_room_time_slot',
            'room_time_slot_id',
            'coupon_id'
        );
    }

    /**
     * Kiểm tra khung giờ có bị block vào 1 ngày cụ thể không
     */
    public function isBlockedOn(string $date): bool
    {
        $blocked = $this->settings['blocked_dates'] ?? [];
        return in_array($date, $blocked);
    }

    /**
     * Lấy tất cả ngày bị block
     */
    public function getBlockedDates(): array
    {
        return $this->settings['blocked_dates'] ?? [];
    }
}