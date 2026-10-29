<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SePayTransaction extends Model
{
    // Tên bảng theo migration (Eloquent tự suy ra "se_pay" nên không khớp).
    protected $table = 'sepay_transactions';

    protected $fillable = ['sepay_id', 'partner_sepay_account_id', 'order_id', 'payment_code', 'amount', 'reference_code', 'transaction_at', 'status', 'failure_reason', 'payload'];

    protected $casts = ['payload' => 'array', 'transaction_at' => 'datetime'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(PartnerSePayAccount::class, 'partner_sepay_account_id');
    }
}
