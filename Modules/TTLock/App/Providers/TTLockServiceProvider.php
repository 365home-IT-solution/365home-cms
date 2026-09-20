<?php

namespace Modules\TTLock\App\Providers;

use Illuminate\Support\ServiceProvider;

class TTLockServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'TTLock';
    protected string $moduleNameLower = 'ttlock';

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/migrations'));
        $this->loadViewsFrom(module_path($this->moduleName, 'resources/views'), $this->moduleNameLower);
    }

    public function register(): void
    {
        //
    }
}
