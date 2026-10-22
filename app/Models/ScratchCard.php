<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payment\Entities\Order;
use Modules\Promotion\App\Models\Coupon;

// Thẻ cào của khách — sinh từ đơn đã trả phòng (hoặc thưởng đánh giá ảnh / giới thiệu). Phần thưởng do BE bốc khi cào.
class ScratchCard extends Model
{
    use HasUlids;

    public const STATUS_AVAILABLE = 'available';
    public const STATUS_SCRATCHED = 'scratched';
    public const STATUS_EXPIRED   = 'expired';
    public const STATUS_VOIDED    = 'voided';

    protected $fillable = [
        'customer_id', 'order_id', 'source', 'status', 'expires_at', 'scratched_at',
        'prize_id', 'prize_label', 'prize_type', 'prize_tier', 'coupon_id',
    ];

    protected $casts = [
        'expires_at'   => 'datetime',
        'scratched_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(ScratchCardPrize::class, 'prize_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class)->withoutGlobalScopes();
    }
}
