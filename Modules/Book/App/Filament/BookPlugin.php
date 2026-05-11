<?php

namespace Modules\Book\App\Filament;

use Coolsam\Modules\Concerns\ModuleFilamentPlugin;
use Filament\Contracts\Plugin;
use Filament\Panel;

class BookPlugin implements Plugin
{
    use ModuleFilamentPlugin;

    public function getModuleName(): string
    {
        return 'Book';
    }

    public function getId(): string
    {
        return 'book';
    }

    public function boot(Panel $panel): void
    {
        // TODO: Implement boot() method.
    }
}
