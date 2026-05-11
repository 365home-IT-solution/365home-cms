<?php

declare(strict_types=1);

namespace Modules\Menu\Entities;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Menu\App\Filament\FilamentMenuBuilderPlugin;
use Modules\Menu\Contracts\MenuPanelable;
use Modules\Menu\Enums\LinkTarget;
use Modules\Page\Entities\Page;

class MenuItem extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('filament-menu-builder.tables.menu_items', parent::getTable());
    }

    public function getFullUrl(): string
    {
        if ($this->route_name) {
            return route($this->route_name);
        }

        $url = $this->url;
        $parent = $this->parent;
        while ($parent) {
            $url = $parent->url . '/' . ltrim($url, '/');
            $parent = $parent->parent;
        }
        return url($url);
    }

    protected function casts(): array
    {
        return [
            'order' => 'int',
            'target' => LinkTarget::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $menuItem) {
            $menuItem->children->each->delete();
        });
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(FilamentMenuBuilderPlugin::get()->getMenuModel());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')
            ->with('children')
            ->orderBy('order');
    }

    public function linkable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function url(): Attribute
    {
        return Attribute::get(function (?string $value) {
            return match (true) {
                $this->linkable instanceof MenuPanelable => $this->linkable->getMenuPanelUrlUsing()($this->linkable),
                default => $value,
            };
        });
    }

    protected function type(): Attribute
    {
        return Attribute::get(function () {
            return match (true) {
                $this->linkable instanceof MenuPanelable => $this->linkable->getMenuPanelName(),
                default => __('menu::menu-builder.custom_link'),
            };
        });
    }

    public function pages(): HasMany {
        return $this->hasMany(Page::class, 'page_id');
    }
}
