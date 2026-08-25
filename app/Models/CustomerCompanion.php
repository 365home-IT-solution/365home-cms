<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerCompanion extends Model
{
    protected $fillable = [
        'customer_id',
        'full_name',
        'cccd_front',
        'cccd_back',
        'cccd_data',
    ];

    protected $casts = [
        'cccd_data' => 'array',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    // Số lần companion này được gắn vào 1 đơn (order_guest_cccds.companion_id) — dùng để đếm
    // "số lần xuất hiện trong các đơn" ở Admin\CustomerCompanionController::index().
    public function orderGuestCccds(): HasMany
    {
        return $this->hasMany(\Modules\Payment\Entities\OrderGuestCccd::class, 'companion_id');
    }
}
