<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Một lần thanh toán phí gói dịch vụ (có mã giao dịch; tiền về tài khoản PayOS của công ty).
class SubscriptionPayment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID = 'paid';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING   => 'Chờ thanh toán',
        self::STATUS_PAID      => 'Đã thanh toán',
        self::STATUS_EXPIRED   => 'Hết hạn',
        self::STATUS_CANCELLED => 'Đã huỷ',
    ];

    protected $fillable = [
        'partner_id', 'plan_id', 'transaction_code', 'amount_vnd', 'months', 'status', 'source', 'is_renewal',
        'payos_order_code', 'payos_payment_link_id', 'payos_checkout_url', 'payos_qr_code', 'payos_bank_bin',
        'payos_account_number', 'payos_account_name', 'payos_expired_at', 'bank_reference', 'paid_at', 'extends_to', 'note',
    ];

    protected $casts = [
        'amount_vnd'       => 'integer',
        'months'           => 'integer',
        'is_renewal'       => 'boolean',
        'payos_order_code' => 'integer',
        'payos_expired_at' => 'datetime',
        'paid_at'          => 'datetime',
        'extends_to'       => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function toApi(): array
    {
        return [
            'id'               => $this->id,
            'transaction_code' => $this->transaction_code,
            'plan'             => $this->plan?->only(['id', 'code', 'name']),
            'amount_vnd'       => $this->amount_vnd,
            'months'           => $this->months,
            'status'           => $this->status,
            'status_label'     => self::STATUSES[$this->status] ?? $this->status,
            'is_renewal'       => $this->is_renewal,
            'source'           => $this->source,
            'paid_at'          => $this->paid_at?->toIso8601String(),
            'extends_to'       => $this->extends_to?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'bank_reference'   => $this->bank_reference,
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
