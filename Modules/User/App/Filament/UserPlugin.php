<?php

declare(strict_types=1);

namespace Modules\User\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class UserPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'User';
    }

    public function getId(): string
    {
        return 'user';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
