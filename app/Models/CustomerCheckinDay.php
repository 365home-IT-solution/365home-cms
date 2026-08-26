<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCheckinDay extends Model
{
    protected $fillable = [
        'customer_checkin_cycle_id',
        'customer_id',
        'checkin_date',
        'source',
    ];

    protected $casts = [
        'checkin_date' => 'date',
    ];

    public function cycle(): BelongsTo
    {
        return $this->belongsTo(CustomerCheckinCycle::class, 'customer_checkin_cycle_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
