<?php

declare(strict_types=1);

namespace Modules\Invoice\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Payment\Entities\OrderItem;

class InvoiceLine extends Model
{
    protected $fillable = [
        'invoice_id',
        'order_item_id',
        'description',
        'unit',
        'quantity',
        'unit_price',
        'vat_rate',
        'amount',
        'sort_order',
    ];

    protected $casts = [
        'quantity'  => 'decimal:2',
        'vat_rate'  => 'decimal:2',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }
}
