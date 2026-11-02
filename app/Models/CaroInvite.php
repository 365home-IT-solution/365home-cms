<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Lời mời đấu caro. invitee_id = null là lời mời mở (ai có mã/link cũng nhận được), có invitee_id là mời đích danh theo SĐT.
class CaroInvite extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    protected $fillable = ['code', 'inviter_id', 'invitee_id', 'status', 'match_id', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    // Còn nhận được không — lời mời quá hạn mà lệnh caro:sweep chưa kịp đánh dấu cũng coi như hết hạn
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
