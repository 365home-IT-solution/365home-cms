<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSticker extends Model
{
    protected $fillable = ['pack_id', 'image', 'sort_order'];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function pack(): BelongsTo
    {
        return $this->belongsTo(GameStickerPack::class, 'pack_id');
    }

    public function url(): ?string
    {
        return $this->image ? asset($this->image) : null;
    }
}
