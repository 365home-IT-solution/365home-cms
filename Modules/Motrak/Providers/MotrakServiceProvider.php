<?php

namespace Modules\Motrak\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Motrak\App\Contracts\HistoryPriorityResolver;
use Modules\Motrak\App\Models\MotrakVehicle;

class MotrakServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'Motrak';

    protected string $moduleNameLower = 'motrak';

    public function boot(): void
    {
        $this->mergeConfigFrom(module_path($this->moduleName, 'Config/config.php'), $this->moduleNameLower);
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        $this->loadViewsFrom(module_path($this->moduleName, 'Resources/views'), $this->moduleNameLower);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Motrak\App\Console\SyncCommand::class,
                \Modules\Motrak\App\Console\PruneCommand::class,
                \Modules\Motrak\App\Console\SeedSampleCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        // Mặc định không xe nào cần lưu lịch sử đầy đủ; module khác (VD thuê xe) ghi đè binding này.
        $this->app->bindIf(HistoryPriorityResolver::class, fn () => new class implements HistoryPriorityResolver {
            public function needsFullHistory(MotrakVehicle $vehicle): bool
            {
                return false;
            }
        });
    }
}
