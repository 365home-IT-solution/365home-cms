<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppPage extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'description',
        'is_active',
        'content',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'content'   => 'array',
    ];

    public function sections(): HasMany
    {
        return $this->hasMany(AppSection::class, 'page_id')
            ->orderBy('sort_order');
    }
}
