<?php

namespace Modules\Invoice\App\Providers;

use Illuminate\Support\ServiceProvider;

class InvoiceServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Invoice';
    protected string $moduleNameLower = 'invoice';

    public function boot(): void
    {
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        $this->loadViewsFrom(module_path($this->moduleName, 'resources/views'), $this->moduleNameLower);
    }

    public function register(): void
    {
        //
    }
}
