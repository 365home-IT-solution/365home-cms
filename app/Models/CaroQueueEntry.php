<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Khách đang chờ ghép trận caro (player_count: chờ trận 2 hay 3 người). last_seen_at được cập nhật mỗi lần app hỏi hàng chờ — quá lâu không hỏi coi như đã rời.
class CaroQueueEntry extends Model
{
    protected $table = 'caro_queue';

    protected $primaryKey = 'customer_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['customer_id', 'player_count', 'match_id', 'last_seen_at'];

    protected $casts = [
        'player_count' => 'integer',
        'last_seen_at' => 'datetime',
    ];
}
