<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Ván đang chơi của 1 khách với 1 game: bộ bài đã trộn + các vị trí đã lật + các lá khách đã đánh dấu (giữ qua các lần trộn).
class MiniGameSession extends Model
{
    protected $fillable = [
        'mini_game_id',
        'customer_id',
        'deck',
        'drawn',
        'marked',
        'shuffle_count',
    ];

    protected $casts = [
        'deck'   => 'array',
        'drawn'  => 'array',
        'marked' => 'array',
    ];
}
