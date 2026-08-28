<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PriceBoardTimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'price_board_item_id',
        'timeslot_id',
        'price',
        'checkin',
        'checkout',
        'over_night',
        'status',
    ];

    protected $casts = [
        'over_night' => 'boolean',
    ];

    public function priceBoardItem()
    {
        return $this->belongsTo(PriceBoardItem::class);
    }

    public function timeslot()
    {
        return $this->belongsTo(TimeSlot::class, 'timeslot_id');
    }
}
