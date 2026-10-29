<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Yêu cầu thanh toán qua SePay của 1 đơn — xem migration create_sepay_payment_links_table.
class SePayPaymentLink extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    protected $table = 'sepay_payment_links';

    protected $fillable = ['order_id', 'code', 'ref_code', 'amount', 'status', 'expires_at', 'paid_at', 'sepay_transaction_id'];

    protected $casts = ['expires_at' => 'datetime', 'paid_at' => 'datetime'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Nội dung chuyển khoản khách phải ghi (SePay tách mã theo tiền tố DH). */
    public function paymentCode(): string
    {
        return \Illuminate\Support\Str::upper((string) \App\Support\SePayConfig::get('code_prefix', 'DH')) . $this->code;
    }
}
