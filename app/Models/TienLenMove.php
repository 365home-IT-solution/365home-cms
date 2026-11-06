<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// Nhật ký từng sự kiện của 1 ván Tiến lên (để xem lại ván / giải quyết khiếu nại).
class TienLenMove extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['match_id', 'event_no', 'seat', 'type', 'cards', 'payload', 'created_at'];

    protected $casts = [
        'cards'   => 'array',
        'payload' => 'array',
    ];
}
