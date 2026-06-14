<?php

namespace Modules\Promotion\App\Models;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Product\App\Models\RoomTimeSlot;
use Modules\Product\App\Models\Product;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'description',
        'type',
        'value',
        'apply_type',
        'room_id',
        'min_order_value',
        'max_discount',
        'usage_limit',
        'used_count',
        'start_at',
        'end_at',
        'is_active',
        'created_by',
        'customer_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
        'value' => 'decimal:2',
        'min_order_value' => 'decimal:2',
        'max_discount' => 'decimal:2',
    ];

    /**
     * Quan hệ nhiều-nhiều với RoomTimeSlot
     * Chỉ dùng khi apply_type = 'specific_slot'
     */
    public function roomTimeSlots()
    {
        return $this->belongsToMany(
            RoomTimeSlot::class,
            'coupon_room_time_slot',
            'coupon_id',
            'room_time_slot_id'
        );
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isPersonal(): bool
    {
        return $this->customer_id !== null;
    }

    /**
     * Quan hệ với Room (Product)
     * Dùng khi apply_type = 'specific_room'
     */
    public function room()
    {
        return $this->belongsTo(Product::class, 'room_id');
    }

    /**
     * Kiểm tra coupon có áp dụng cho room_time_slot này không
     */
    public function isApplicableToSlot(RoomTimeSlot $slot): bool
    {
        // Kiểm tra active và thời gian
        if (!$this->is_active) {
            return false;
        }

        $now = now();
        if ($this->start_at && $now->lt($this->start_at)) {
            return false;
        }
        if ($this->end_at && $now->gt($this->end_at)) {
            return false;
        }

        // Kiểm tra usage limit
        if ($this->usage_limit && $this->used_count >= $this->usage_limit) {
            return false;
        }

        // Kiểm tra apply_type
        switch ($this->apply_type) {
            case 'all_rooms':
                return true;

            case 'specific_room':
                return $slot->room_id === $this->room_id;

            case 'specific_slot':
                return $this->roomTimeSlots()->where('room_time_slot_id', $slot->id)->exists();

            default:
                return false;
        }
    }

    /**
     * Tính giá trị giảm giá
     */
    public function calculateDiscount(float $amount): float
    {
        if ($this->type === 'percentage') {
            $discount = ($amount * $this->value) / 100;
        } else {
            $discount = $this->value;
        }

        // Áp dụng max_discount nếu có
        if ($this->max_discount && $discount > $this->max_discount) {
            $discount = $this->max_discount;
        }

        return min($discount, $amount); // Không được vượt quá tổng tiền
    }

    /**
     * Tăng số lần sử dụng
     */
    public function incrementUsage(): void
    {
        $this->increment('used_count');
    }
}