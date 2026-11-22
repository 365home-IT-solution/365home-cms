<?php

namespace Modules\Payment\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Phiên thanh toán thẻ Pay2S của 1 đơn — xem migration create_pay2s_card_payments_table.
class Pay2SCardPayment extends Model
{
    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const CANCELLED = 'cancelled';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    public const REVIEW = 'review';

    // Khoản review đã được hoàn tiền / xử lý xong thủ công (admin đánh dấu).
    public const RESOLVED = 'resolved';

    protected $table = 'pay2s_card_payments';

    protected $fillable = [
        'order_id', 'kind', 'pay2s_order_id', 'amount', 'card_brand', 'session_id', 'environment', 'payment_url',
        'status', 'failure_reason', 'trans_id', 'expires_at', 'paid_at', 'resolved_at', 'resolved_by', 'resolution_note', 'payload',
    ];

    protected $casts = ['expires_at' => 'datetime', 'paid_at' => 'datetime', 'resolved_at' => 'datetime', 'payload' => 'array', 'amount' => 'integer'];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Dữ liệu Pay2S gửi về để hiển thị / trả API: bỏ khoá truy cập và chữ ký (không cần cho đối soát). */
    public function safePayload(): array
    {
        return \Illuminate\Support\Arr::except((array) $this->payload, ['accessKey', 'm2signature']);
    }

    public function isOpen(): bool
    {
        return $this->status === self::PENDING && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}
