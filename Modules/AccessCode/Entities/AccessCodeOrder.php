<?php

namespace Modules\AccessCode\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Payment\Entities\Order;

class AccessCodeOrder extends Model
{
    protected $fillable = [
        'access_code_id',
        'order_id',
        'assigned_at',
    ];
    public function accessCode()
    {
        return $this->belongsTo(AccessCode::class, 'access_code_id');
    }
    public function order()
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
