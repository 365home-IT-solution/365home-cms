<?php

declare(strict_types=1);

namespace Modules\AuditLog\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class AuditLogPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'AuditLog';
    }

    public function getId(): string
    {
        return 'auditlog';
    }

    public function boot(Panel $panel): void
    {
        //
    }
}
