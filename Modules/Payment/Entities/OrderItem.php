<?php

namespace Modules\Payment\Entities;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\Product\App\Models\Product;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class OrderItem extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'order_id',
        'product_id',
        'name',
        'price',
        'discount',
        'vat',
        'quantity',
        'is_shipped',
        'checkin_date',
        'checkout_date',
        'slot_label',
        'slot_day',
        'slot_raw_day',
        'extra_fee',
        'guest_count',
        'expiry_notified',
        'checkout_notified',
    ];

    protected $casts = [
        'price' => 'integer',
        'checkin_date' => 'datetime',
        'checkout_date' => 'datetime',
    ];

    protected $dates = [
        'checkin_date',
        'checkout_date',
        'created_at',
        'updated_at',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getFormattedCheckinDateAttribute()
    {
        return $this->checkin_date ? $this->checkin_date->format('d/m/Y H:i') : null;
    }
    
}