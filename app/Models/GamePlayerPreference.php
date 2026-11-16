<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// Tuỳ chọn âm thanh của khách theo từng game (khoá chính kép customer_id + game).
class GamePlayerPreference extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['customer_id', 'game', 'sound_pack', 'sfx_enabled', 'music_enabled', 'sfx_volume', 'music_volume'];

    protected $casts = [
        'sfx_enabled'   => 'boolean',
        'music_enabled' => 'boolean',
        'sfx_volume'    => 'integer',
        'music_volume'  => 'integer',
    ];

    public const DEFAULTS = [
        'sfx_enabled'   => true,
        'music_enabled' => true,
        'sfx_volume'    => 100,
        'music_volume'  => 70,
    ];

    // Lưu theo khoá kép (Eloquent không cập nhật được model không có khoá chính đơn)
    protected function setKeysForSaveQuery($query): Builder
    {
        return $query->where('customer_id', $this->getAttribute('customer_id'))->where('game', $this->getAttribute('game'));
    }
}
