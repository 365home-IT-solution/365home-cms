<?php

namespace Modules\Minihouse\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Minihouse\App\Models\Concerns\LogsMinihouseActivity;

class InvoicePayment extends Model
{
    use LogsMinihouseActivity;

    public const METHOD_CASH     = 'tien_mat';
    public const METHOD_TRANSFER = 'chuyen_khoan';
    public const METHOD_OTHER    = 'khac';

    protected $table = 'minihouse_invoice_payments';

    protected $fillable = ['invoice_id', 'amount', 'paid_at', 'payment_method', 'note', 'created_by'];

    // float — 'decimal:2' luôn ép hiện đủ 2 số lẻ dù giá trị là số nguyên.
    protected $casts = [
        'amount'  => 'float',
        'paid_at' => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
