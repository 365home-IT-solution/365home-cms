<?php

namespace Modules\AppPage\App\Providers;

use Illuminate\Support\ServiceProvider;

class AppPageServiceProvider extends ServiceProvider
{
    protected $moduleName = 'AppPage';
    protected $moduleNameLower = 'apppage';

    public function boot(): void
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/migrations'));
    }

    public function register(): void
    {
        //
    }

    protected function registerConfig(): void
    {
        $configPath = module_path($this->moduleName, 'config/config.php');
        if (file_exists($configPath)) {
            $this->mergeConfigFrom($configPath, $this->moduleNameLower);
        }
    }

    protected function registerViews(): void
    {
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        if (is_dir($sourcePath)) {
            $this->loadViewsFrom($sourcePath, $this->moduleNameLower);
        }
    }

    protected function registerTranslations(): void
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'lang'), $this->moduleNameLower);
        }
    }
}
