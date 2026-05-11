<?php

declare(strict_types=1);

namespace Modules\Menu\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Menu\App\Filament\FilamentMenuBuilderPlugin;

class MenuLocation extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('filament-menu-builder.tables.menu_locations', parent::getTable());
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(FilamentMenuBuilderPlugin::get()->getMenuModel());
    }
}
