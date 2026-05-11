<?php

namespace Modules\QA\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class QAPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'QA';
    }

    public function getId(): string
    {
        return 'qa';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
