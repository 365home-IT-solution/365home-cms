<?php

declare(strict_types=1);

namespace Modules\Menu\Entities;


use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Footer\Entities\FooterColumn;
use Modules\Menu\App\Filament\FilamentMenuBuilderPlugin;

class Menu extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('filament-menu-builder.tables.menus', parent::getTable());
    }

    protected function casts(): array
    {
        return [
            'is_visible' => 'bool',
        ];
    }

    public function locations(): HasMany
    {
        return $this->hasMany(FilamentMenuBuilderPlugin::get()->getMenuLocationModel());
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(FilamentMenuBuilderPlugin::get()->getMenuItemModel())
            ->whereNull('parent_id')
            ->orderBy('parent_id')
            ->orderBy('order')
            ->with('children');
    }

    public static function location(string $location): ?self
    {
        return FilamentMenuBuilderPlugin::get()
            ->getMenuLocationModel()::with(['menu' => fn (Builder $query) => $query->where('is_visible', true)])
            ->where('location', $location)
            ->first()?->menu;
    }

    public function footerColumn(): BelongsTo {
        return $this->belongsTo(FooterColumn::class);
    }
}
