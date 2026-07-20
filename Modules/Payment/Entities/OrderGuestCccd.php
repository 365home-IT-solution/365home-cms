<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;

class OrderGuestCccd extends Model
{
    protected $fillable = [
        'order_id',
        'guest_index',
        'cccd_front',
        'cccd_back',
        'cccd_data',
    ];

    protected $casts = [
        'cccd_data' => 'array',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
