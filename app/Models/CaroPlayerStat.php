<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Thành tích caro của 1 khách, kèm mã người chơi cố định để bạn bè mời đích danh (CaroService::playerCode).
class CaroPlayerStat extends Model
{
    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['customer_id', 'player_code', 'wins', 'losses', 'draws', 'pending_wins', 'streak_days', 'last_played_on'];

    protected $casts = [
        'wins'         => 'integer',
        'losses'       => 'integer',
        'draws'        => 'integer',
        'pending_wins' => 'integer',
        'streak_days'  => 'integer',
        'last_played_on' => 'date',
    ];
}
