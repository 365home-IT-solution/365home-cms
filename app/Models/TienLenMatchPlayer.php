<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Một người thật trong 1 ván Tiến lên (ván với bot: 1 dòng; ván phòng: mỗi người 1 dòng) — hạng + điểm thưởng riêng.
class TienLenMatchPlayer extends Model
{
    public const STATUS_PLAYING  = 'playing';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_LEFT     = 'left';
    public const STATUS_AUTO     = 'auto';

    protected $fillable = ['match_id', 'seat', 'customer_id', 'status', 'rank', 'score', 'reward_points'];

    protected $casts = [
        'seat'          => 'integer',
        'rank'          => 'integer',
        'score'         => 'integer',
        'reward_points' => 'integer',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(TienLenMatch::class, 'match_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
