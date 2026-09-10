<?php

namespace Modules\Minihouse\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 1 dòng phụ thu đã áp vào hoá đơn — SNAPSHOT name/amount tại thời điểm lập hoá đơn, không tham
// chiếu sống vào Surcharge::amount (xem migration). surcharge_id chỉ để biết nguồn gốc.
class InvoiceItem extends Model
{
    protected $table = 'minihouse_invoice_items';

    protected $fillable = ['invoice_id', 'surcharge_id', 'name', 'amount'];

    // float — 'decimal:2' luôn ép hiện đủ 2 số lẻ (VD "10000.00") dù giá trị là số nguyên.
    protected $casts = [
        'amount' => 'float',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function surcharge(): BelongsTo
    {
        return $this->belongsTo(Surcharge::class);
    }
}
