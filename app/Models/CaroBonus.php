<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Trận thưởng cộng vào tiến độ caro ngoài trận thắng: streak (chơi chuỗi ngày) hoặc invite (mời bạn mới chơi trận đầu).
class CaroBonus extends Model
{
    public const TYPE_STREAK = 'streak';
    public const TYPE_INVITE = 'invite';

    protected $fillable = ['customer_id', 'type', 'match_id'];
}
