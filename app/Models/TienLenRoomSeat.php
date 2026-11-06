<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Một ghế trong phòng Tiến lên: người (customer_id) hoặc bot (is_bot).
class TienLenRoomSeat extends Model
{
    protected $fillable = ['room_id', 'seat', 'customer_id', 'is_bot', 'is_ready', 'last_seen_at'];

    protected $casts = [
        'seat'         => 'integer',
        'is_bot'       => 'boolean',
        'is_ready'     => 'boolean',
        'last_seen_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(TienLenRoom::class, 'room_id');
    }
}
