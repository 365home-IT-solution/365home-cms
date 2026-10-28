<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * BỘ GIÁ của 1 bảng giá — định nghĩa giá/ưu đãi cho một nhóm phòng (gán đích danh hoặc mặc định theo
 * loại phòng). price_board_items là dữ liệu được SINH RA từ bộ giá này (xem PriceBoardV2Service).
 */
class PriceBoardSheet extends Model
{
    public const FIELDS_SET_KEYS = ['full_booking_discount', 'bulk_discount_rules', 'room_config'];

    protected $fillable = [
        'price_board_id', 'name', 'pricing_mode', 'room_style', 'is_default', 'sort_order',
        'price', 'default_checkin', 'default_checkout', 'deposit_1_night', 'deposit_multi_night', 'deposit_min_nights',
        'full_booking_discount', 'bulk_discount_rules', 'room_config', 'fields_set',
        'adjustment_type', 'adjustment_value', 'adjustment_timeslot_ids', 'slot_times', 'weekday_prices',
    ];

    protected $casts = [
        'is_default'              => 'boolean',
        'room_style'              => 'integer',
        'price'                   => 'decimal:2',
        'bulk_discount_rules'     => 'array',
        'room_config'             => 'array',
        'fields_set'              => 'array',
        'adjustment_value'        => 'decimal:2',
        'adjustment_timeslot_ids' => 'array',
        'slot_times'              => 'array',
        'weekday_prices'          => 'array',
    ];

    public function board()
    {
        return $this->belongsTo(PriceBoard::class, 'price_board_id');
    }

    public function slots()
    {
        return $this->hasMany(PriceBoardSheetSlot::class, 'sheet_id')->orderBy('sort_order')->orderBy('id');
    }

    public function products()
    {
        return $this->hasMany(PriceBoardSheetProduct::class, 'sheet_id');
    }

    public function isAdjustment(): bool
    {
        return $this->pricing_mode === PriceBoard::MODE_ADJUSTMENT;
    }

    /** Trường ưu đãi này có được khai báo trong bộ giá không (không → giữ giá trị hiện tại của phòng). */
    public function declares(string $field): bool
    {
        return in_array($field, (array) $this->fields_set, true);
    }
}
