<?php

declare(strict_types=1);

namespace Modules\Tag\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class TagPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Tag';
    }

    public function getId(): string
    {
        return 'tag';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
