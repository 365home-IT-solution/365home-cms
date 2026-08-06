<?php

namespace Modules\Product\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
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
}
