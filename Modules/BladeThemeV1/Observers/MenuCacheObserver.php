<?php

namespace Modules\BladeThemeV1\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\BladeThemeV1\Support\ThemeCache;

class MenuCacheObserver
{
    public function saved(Model $model): void
    {
        $this->flush($model);
    }

    public function deleted(Model $model): void
    {
        $this->flush($model);
    }

    private function flush(Model $model): void
    {
        ThemeCache::flushAll();

        $menuId = $model->getAttribute('menu_id') ?? $model->getKey();
        if ($menuId) {
            ThemeCache::flushMenuById((int) $menuId);
        }
    }
}
