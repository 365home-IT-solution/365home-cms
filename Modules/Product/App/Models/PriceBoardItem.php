<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PriceBoardItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'price_board_id',
        'product_id',
        'price',
        'price_unit',
        'full_booking_discount',
        'bulk_discount_rules',
        'room_config',
        'deposit_1_night',
        'deposit_multi_night',
        'deposit_min_nights',
        'default_checkin',
        'default_checkout',
    ];

    protected $casts = [
        'price'               => 'decimal:2',
        'bulk_discount_rules' => 'array',
        'room_config'         => 'array',
    ];

    public function priceBoard()
    {
        return $this->belongsTo(PriceBoard::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function timeSlots()
    {
        return $this->hasMany(PriceBoardTimeSlot::class);
    }
}
