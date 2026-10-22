<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payment\Entities\Order;

// Mảnh khách đã thu thập (mỗi chiến dịch + khách + chi nhánh một dòng).
class CollectionCustomerPiece extends Model
{
    protected $fillable = ['campaign_id', 'customer_id', 'branch_id', 'order_id', 'collected_at'];

    protected $casts = ['collected_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }
}
