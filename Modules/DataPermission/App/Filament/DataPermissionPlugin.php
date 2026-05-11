<?php

declare(strict_types=1);

namespace Modules\DataPermission\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class DataPermissionPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'DataPermission';
    }

    public function getId(): string
    {
        return 'datapermission';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
