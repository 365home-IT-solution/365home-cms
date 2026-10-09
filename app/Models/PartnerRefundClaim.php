<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Yêu cầu hoàn tiền khách của 1 đơn mà tiền nằm ở đối tác (xem App\Services\RefundClaimService).
class PartnerRefundClaim extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_REFUNDED_BY_PARTNER = 'refunded_by_partner';
    public const STATUS_REFUNDED_BY_PLATFORM = 'refunded_by_platform';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_OPEN                 => 'Chờ đối tác hoàn',
        self::STATUS_OVERDUE              => 'Quá hạn — 365home có thể hoàn thay',
        self::STATUS_REFUNDED_BY_PARTNER  => 'Đối tác đã hoàn',
        self::STATUS_REFUNDED_BY_PLATFORM => '365home đã hoàn thay (đã trừ ký quỹ)',
        self::STATUS_CANCELLED            => 'Đã huỷ yêu cầu',
    ];

    /** Còn hiệu lực (chưa hoàn, chưa huỷ). */
    public const ACTIVE = [self::STATUS_OPEN, self::STATUS_OVERDUE];

    protected $fillable = [
        'partner_id', 'order_id', 'order_code', 'amount', 'reason', 'status', 'requested_at', 'due_at',
        'overdue_notified_at', 'resolved_at', 'requested_by', 'resolved_by',
    ];

    protected $casts = [
        'amount'              => 'integer',
        'requested_at'        => 'datetime',
        'due_at'              => 'datetime',
        'overdue_notified_at' => 'datetime',
        'resolved_at'         => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class)->withTrashed();
    }

    public function toApi(): array
    {
        return [
            'id'           => $this->id,
            'order_code'   => $this->order_code,
            'amount'       => $this->amount,
            'reason'       => $this->reason,
            'status'       => $this->status,
            'status_label' => self::STATUSES[$this->status] ?? $this->status,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'due_at'       => $this->due_at?->toIso8601String(),
            'is_overdue'   => in_array($this->status, self::ACTIVE, true) && $this->due_at?->isPast(),
            'resolved_at'  => $this->resolved_at?->toIso8601String(),
        ];
    }
}
