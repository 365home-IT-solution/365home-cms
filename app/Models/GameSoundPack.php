<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bộ âm thanh của 1 mini game (Tiến lên / Caro) — Super Admin quản lý ở Filament "Mini game — Âm thanh" hoặc /api/admin/games/sound-packs.
 * sounds = { mã sự kiện => đường dẫn file tương đối web root }, danh sách sự kiện ở GameSoundPackService::EVENTS.
 */
class GameSoundPack extends Model
{
    protected $fillable = ['game', 'key', 'name', 'is_active', 'is_default', 'sort_order', 'cover_image', 'sounds'];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'sort_order' => 'integer',
        'sounds'     => 'array',
    ];

    public static function url(?string $path): ?string
    {
        return $path ? asset($path) : null;
    }
}
