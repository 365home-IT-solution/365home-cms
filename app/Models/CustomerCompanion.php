<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
