<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\Payment\Entities\Order;

class CccdDeclaration extends Model
{
    protected $fillable = [
        'order_id',
        'full_name',
        'info',
        'cccd_number',
        'checked_in_at',
        'checked_out_at',
        'room_number',
        'reason_for_stay',
        'current_residence',
        'province',
        'ward',
        'address_detail',
    ];

    protected $casts = [
        'checked_in_at'  => 'datetime',
        'checked_out_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
