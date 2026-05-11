<?php

namespace Modules\Payment\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderService extends Model
{
    use HasFactory;

    protected $table = 'order_services';

    protected $fillable = [
        'order_id',
        'service_id',
        'service_name',
        'price',
        'quantity',
        'subtotal',
    ];

    protected $casts = [
        'price'    => 'integer',
        'quantity' => 'integer',
        'subtotal' => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}