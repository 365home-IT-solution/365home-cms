<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// Một mini game khách chơi trên app — xem App\Http\Controllers\Api\MiniGameController.
class MiniGame extends Model
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'thumbnail',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function cards(): HasMany
    {
        return $this->hasMany(MiniGameCard::class);
    }
}
