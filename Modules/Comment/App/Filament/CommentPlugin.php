<?php

declare(strict_types=1);

namespace Modules\Comment\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class CommentPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Comment';
    }

    public function getId(): string
    {
        return 'comment';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
