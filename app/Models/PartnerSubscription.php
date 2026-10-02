<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Đăng ký gói của 1 đối tác (mỗi đối tác đúng 1 dòng). Hết hạn KHÔNG lưu cột — luôn tính theo expires_at (null = không giới hạn).
class PartnerSubscription extends Model
{
    public const STATUS_TRIAL = 'trial';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';

    // Trạng thái hiệu lực (tính động) hiển thị cho người dùng.
    public const STATE_TRIAL = 'trial';
    public const STATE_ACTIVE = 'active';
    public const STATE_EXPIRED = 'expired';
    public const STATE_CANCELLED = 'cancelled';

    public const STATES = [
        self::STATE_TRIAL     => 'Dùng thử',
        self::STATE_ACTIVE    => 'Đang hoạt động',
        self::STATE_EXPIRED   => 'Hết hạn',
        self::STATE_CANCELLED => 'Đã huỷ',
    ];

    protected $fillable = [
        'partner_id', 'plan_id', 'status', 'is_trial', 'started_at', 'expires_at', 'auto_renew',
        'reminders_sent', 'renewal_link_sent_at', 'expired_notified_at', 'note',
    ];

    protected $casts = [
        'is_trial'             => 'boolean',
        'auto_renew'           => 'boolean',
        'started_at'           => 'datetime',
        'expires_at'           => 'datetime',
        'reminders_sent'       => 'array',
        'renewal_link_sent_at' => 'datetime',
        'expired_notified_at'  => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** Có đang được dùng (chưa huỷ, chưa hết hạn)? */
    public function isUsable(): bool
    {
        return $this->status !== self::STATUS_CANCELLED && ! $this->isExpired();
    }

    public function state(): string
    {
        return match (true) {
            $this->status === self::STATUS_CANCELLED => self::STATE_CANCELLED,
            $this->isExpired()                       => self::STATE_EXPIRED,
            $this->is_trial                          => self::STATE_TRIAL,
            default                                  => self::STATE_ACTIVE,
        };
    }

    public function daysLeft(): ?int
    {
        return $this->expires_at ? (int) floor(now()->diffInSeconds($this->expires_at, false) / 86400) : null;
    }
}
