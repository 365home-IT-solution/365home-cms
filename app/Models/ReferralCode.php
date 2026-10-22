<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Mã giới thiệu cố định của một khách (tạo lười ở lần xem đầu tiên).
class ReferralCode extends Model
{
    protected $fillable = ['customer_id', 'code'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
