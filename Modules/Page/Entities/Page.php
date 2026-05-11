<?php

namespace Modules\Page\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Page\Entities\Component;
use Modules\Menu\Entities\MenuItem;

/**
 * @method static create(array|mixed[] $data)
 */
class Page extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'seo_title',
        'seo_description',
        'seo_keywords'
    ];

    public function components()
    {
        return $this->belongsToMany(Component::class, 'comp_pages')
            ->withPivot('order')
            ->orderBy('order');
    }

    public function pageComponents()
    {
        return $this->hasMany(PageComponent::class, 'page_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'page_id');
    }
}
