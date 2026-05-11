<?php

namespace Modules\Form\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class EmailSettingPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Form';
    }

    public function getId(): string
    {
        return 'emailsetting';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}