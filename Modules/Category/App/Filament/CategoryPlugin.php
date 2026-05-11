<?php

declare(strict_types=1);

namespace Modules\Category\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class CategoryPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Category';
    }

    public function getId(): string
    {
        return 'category';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
