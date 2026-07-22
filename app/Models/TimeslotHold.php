<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Product\App\Models\RoomTimeSlot;

class TimeslotHold extends Model
{
    protected $fillable = [
        'room_time_slot_id',
        'date',
        'user_id',
        'expires_at',
    ];

    protected $casts = [
        'date'       => 'date',
        'expires_at' => 'datetime',
    ];

    public function roomTimeSlot(): BelongsTo
    {
        return $this->belongsTo(RoomTimeSlot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
