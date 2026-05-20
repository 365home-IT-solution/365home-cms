<?php

declare(strict_types=1);

namespace Modules\AppPage\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppSection extends Model
{
    protected $fillable = [
        'page_id',
        'key',
        'title',
        'subtitle',
        'layout',
        'show_arrow',
        'view_all_url',
        'sort_order',
        'filter_config',
        'badge_config',
        'is_active',
    ];

    protected $casts = [
        'show_arrow'    => 'boolean',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
        'filter_config' => 'array',
        'badge_config'  => 'array',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(AppPage::class, 'page_id');
    }
}
