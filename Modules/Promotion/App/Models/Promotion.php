<?php

namespace Modules\Promotion\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Product\App\Models\RoomTimeSlot;

class Promotion extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'value',
        'tuimu_rewards',
        'start_at',
        'end_at',
        'lable_client',
        'image',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_active' => 'boolean',
    ];


    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function roomTimeSlots()
    {
        return $this->belongsToMany(
            RoomTimeSlot::class,
            'promotion_room_time_slot',
            'promotion_id',
            'room_time_slot_id'
        );
    }

}
