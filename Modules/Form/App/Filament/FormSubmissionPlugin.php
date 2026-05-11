<?php

namespace Modules\Form\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FormSubmissionPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Form';
    }

    public function getId(): string
    {
        return 'formsubmission';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}