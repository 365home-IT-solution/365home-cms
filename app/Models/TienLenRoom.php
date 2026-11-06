<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Phòng Tiến lên nhiều người — xem App\Services\TienLenRoomService.
class TienLenRoom extends Model
{
    use HasUlids;

    public const STATUS_WAITING = 'waiting';
    public const STATUS_PLAYING = 'playing';
    public const STATUS_CLOSED  = 'closed';

    public const STATUS_LABELS = [
        self::STATUS_WAITING => 'Đang chờ',
        self::STATUS_PLAYING => 'Đang chơi',
        self::STATUS_CLOSED  => 'Đã đóng',
    ];

    protected $fillable = ['code', 'host_customer_id', 'status', 'max_players', 'is_public', 'current_match_id', 'last_match_id'];

    protected $casts = [
        'max_players' => 'integer',
        'is_public'   => 'boolean',
    ];

    public function seats(): HasMany
    {
        return $this->hasMany(TienLenRoomSeat::class, 'room_id')->orderBy('seat');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'host_customer_id');
    }

    public function currentMatch(): BelongsTo
    {
        return $this->belongsTo(TienLenMatch::class, 'current_match_id');
    }
}
