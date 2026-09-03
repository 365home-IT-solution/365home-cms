<?php

namespace Modules\Payment\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Database\Eloquent\Factory;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Modules\Payment\Entities\GHNConfiguration;
use Modules\Payment\Entities\GHTKConfiguration;
use Modules\Payment\Entities\PaymentConfiguration;

class PaymentServiceProvider extends ServiceProvider
{
    /**
     * @var string $moduleName
     */
    protected $moduleName = 'Payment';

    /**
     * @var string $moduleNameLower
     */
    protected $moduleNameLower = 'payment';

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerTranslations();
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/migrations'));

        try {
            $config = PaymentConfiguration::first();
            if ($config) {
                Config::set('payos.client_id',    $config->client_id);
                Config::set('payos.api_key',      $config->api_key);
                Config::set('payos.checksum_key', $config->checksum_key);
            }

            $ghnConfig = GHNConfiguration::first();
            if ($ghnConfig) {
                Config::set('ghn.api_token',           $ghnConfig->api_token);
                Config::set('ghn.client_id',           $ghnConfig->client_id);
                Config::set('ghn.shop_id',             $ghnConfig->shop_id);
                Config::set('ghn.required_note',       $ghnConfig->required_note);
                Config::set('ghn.return_phone',        $ghnConfig->return_phone);
                Config::set('ghn.return_address',      $ghnConfig->return_address);
                Config::set('ghn.return_district_id',  $ghnConfig->return_district_id);
                Config::set('ghn.return_ward_code',    $ghnConfig->return_ward_code);
                Config::set('ghn.from_name',           $ghnConfig->from_name);
                Config::set('ghn.from_phone',          $ghnConfig->from_phone);
                Config::set('ghn.from_address',        $ghnConfig->from_address);
                Config::set('ghn.from_ward_name',      $ghnConfig->from_ward_name);
                Config::set('ghn.from_district_name',  $ghnConfig->from_district_name);
                Config::set('ghn.from_province_name',  $ghnConfig->from_province_name);
            }

            $ghtkConfig = GHTKConfiguration::first();
            if ($ghtkConfig) {
                Config::set('ghtk.api_token',     $ghtkConfig->api_token);
                Config::set('ghtk.partner_code',  $ghtkConfig->partner_code);
                Config::set('ghtk.pick_name',     $ghtkConfig->pick_name);
                Config::set('ghtk.pick_address',  $ghtkConfig->pick_address);
                Config::set('ghtk.pick_province', $ghtkConfig->pick_province);
                Config::set('ghtk.pick_district', $ghtkConfig->pick_district);
                Config::set('ghtk.pick_ward',     $ghtkConfig->pick_ward);
                Config::set('ghtk.pick_tel',      $ghtkConfig->pick_tel);
            }
        } catch (\Exception $e) {
            // DB chưa sẵn sàng (lúc migrate lần đầu) — bỏ qua
        }
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    /**
     * Register config.
     *
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');
        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'),
            $this->moduleNameLower
        );
    }

    /**
     * Register views.
     *
     * @return void
     */
    public function registerViews()
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);

        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

    /**
     * Register translations.
     *
     * @return void
     */
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/' . $this->moduleNameLower);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, $this->moduleNameLower);
            $this->loadJsonTranslationsFrom($langPath);
        } else {
            $this->loadTranslationsFrom(module_path($this->moduleName, 'Resources/lang'), $this->moduleNameLower);
            $this->loadJsonTranslationsFrom(module_path($this->moduleName, 'Resources/lang'));
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }

    private function getPublishableViewPaths(): array
    {
        $paths = [];
        foreach (\Config::get('view.paths') as $path) {
            if (is_dir($path . '/modules/' . $this->moduleNameLower)) {
                $paths[] = $path . '/modules/' . $this->moduleNameLower;
            }
        }
        return $paths;
    }
}
