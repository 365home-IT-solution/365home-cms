<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Một yêu cầu NẠP KÝ QUỸ qua QR PayOS của 365home (tiền về tài khoản 365home, không về kênh của đối tác).
// Webhook xác nhận → App\Services\EscrowPayosService::markPaid() ghi bút toán 'deposit'.
class PartnerEscrowDeposit extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING   => 'Chờ thanh toán',
        self::STATUS_PAID      => 'Đã nạp',
        self::STATUS_EXPIRED   => 'Hết hạn',
        self::STATUS_CANCELLED => 'Đã huỷ',
    ];

    protected $fillable = [
        'partner_id', 'transaction_code', 'amount', 'status', 'payos_order_code', 'payos_payment_link_id', 'payos_checkout_url',
        'payos_qr_code', 'payos_bank_bin', 'payos_account_number', 'payos_account_name', 'payos_expired_at', 'bank_reference', 'paid_at', 'created_by',
    ];

    protected $casts = [
        'amount'           => 'integer',
        'payos_order_code' => 'integer',
        'payos_expired_at' => 'datetime',
        'paid_at'          => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function toApi(): array
    {
        return [
            'id'               => $this->id,
            'transaction_code' => $this->transaction_code,
            'amount'           => $this->amount,
            'status'           => $this->status,
            'status_label'     => self::STATUSES[$this->status] ?? $this->status,
            'paid_at'          => $this->paid_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            // Chỉ trả thông tin thanh toán khi còn chờ.
            'payment'          => $this->status === self::STATUS_PENDING ? [
                'checkout_url' => $this->payos_checkout_url,
                'qr_code'      => $this->payos_qr_code,
                'expired_at'   => $this->payos_expired_at?->toIso8601String(),
                'account'      => [
                    'bank_bin'       => $this->payos_bank_bin,
                    'account_number' => $this->payos_account_number,
                    'account_name'   => $this->payos_account_name,
                    'content'        => $this->transaction_code,
                ],
            ] : null,
        ];
    }
}
