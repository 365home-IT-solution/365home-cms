<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Lời mời đấu caro — ai có mã/link cũng nhận được. Lời mời 3 người là phòng chờ: guest_ids là những người đã vào, đủ player_count - 1
// người thì tạo trận. invitee_id = người nhận đầu tiên (giữ cho dữ liệu cũ chưa có guest_ids). notified_ids = những người được báo lời mời
// đích danh (theo SĐT / mã người chơi) — đây là lời mời đến của họ.
class CaroInvite extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_ACCEPTED  = 'accepted';
    public const STATUS_DECLINED  = 'declined';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    protected $fillable = ['code', 'inviter_id', 'invitee_id', 'player_count', 'guest_ids', 'notified_ids', 'status', 'match_id', 'expires_at'];

    protected $attributes = [
        'player_count' => 2,
    ];

    protected $casts = [
        'player_count' => 'integer',
        'guest_ids'    => 'array',
        'notified_ids' => 'array',
        'expires_at'   => 'datetime',
    ];

    /** @return array<int, string> */
    public function guestIds(): array
    {
        return array_values(array_map('strval', $this->guest_ids ?: array_filter([$this->invitee_id])));
    }

    // Số chỗ còn trống cho bạn vào phòng
    public function slotsLeft(): int
    {
        return max(0, $this->player_count - 1 - count($this->guestIds()));
    }

    // Còn nhận được không — lời mời quá hạn mà lệnh caro:sweep chưa kịp đánh dấu cũng coi như hết hạn
    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at->isFuture();
    }
}
