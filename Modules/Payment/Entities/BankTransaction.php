<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Giao dịch tiền vào do SePay báo về — xem migration create_bank_transactions_table và App\Services\Payment\SepayWebhookService.
class BankTransaction extends Model
{
    public const STATUS_MATCHED = 'matched';
    public const STATUS_UNMATCHED = 'unmatched';
    public const STATUS_AMOUNT_MISMATCH = 'amount_mismatch';
    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const APPLIED_PAYMENT = 'payment';
    public const APPLIED_REMAINING = 'remaining';
    public const APPLIED_EXTRA_CHARGE = 'extra_charge';

    protected $fillable = [
        'provider',
        'provider_txn_id',
        'partner_id',
        'gateway',
        'account_number',
        'amount',
        'content',
        'reference_code',
        'transacted_at',
        'order_id',
        'status',
        'applied_as',
        'note',
        'payload',
    ];

    protected $casts = [
        'amount'        => 'integer',
        'transacted_at' => 'datetime',
        'payload'       => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class)->withoutGlobalScopes();
    }
}
