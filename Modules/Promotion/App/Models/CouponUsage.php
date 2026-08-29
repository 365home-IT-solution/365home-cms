<?php

namespace Modules\Promotion\App\Models;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payment\Entities\Order;

class CouponUsage extends Model
{
    protected $fillable = [
        'coupon_id',
        'customer_id',
        'order_id',
        'category_id',
        'code',
        'discount_amount',
        'used_at',
    ];

    protected $casts = [
        'used_at'         => 'datetime',
        'discount_amount' => 'integer',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
