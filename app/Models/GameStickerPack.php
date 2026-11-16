<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bộ sticker dùng trong chat mini game — games = các game được dùng bộ này. Xem App\Services\GameStickerService.
 */
class GameStickerPack extends Model
{
    protected $fillable = ['key', 'name', 'icon_image', 'games', 'is_active', 'sort_order'];

    protected $casts = [
        'games'      => 'array',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function stickers(): HasMany
    {
        return $this->hasMany(GameSticker::class, 'pack_id')->orderBy('sort_order')->orderBy('id');
    }

    public function supports(string $game): bool
    {
        return in_array($game, $this->games ?? [], true);
    }
}
