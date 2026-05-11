<?php

declare(strict_types=1);

namespace Modules\Post\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class PostPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Post';
    }

    public function getId(): string
    {
        return 'post';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
