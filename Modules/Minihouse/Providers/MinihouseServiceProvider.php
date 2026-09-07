<?php

namespace Modules\Minihouse\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Minihouse\App\Models\Contract;
use Modules\Minihouse\App\Models\ContractTenant;
use Modules\Minihouse\App\Models\Invoice;
use Modules\Minihouse\App\Models\InvoicePayment;
use Modules\Minihouse\App\Models\Tenant;
use Modules\Minihouse\App\Observers\ContractObserver;
use Modules\Minihouse\App\Observers\ContractTenantObserver;
use Modules\Minihouse\App\Observers\InvoiceObserver;
use Modules\Minihouse\App\Observers\InvoicePaymentObserver;
use Modules\Minihouse\App\Observers\TenantObserver;

class MinihouseServiceProvider extends ServiceProvider
{
    protected $moduleName = 'Minihouse';

    protected $moduleNameLower = 'minihouse';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));

        // Tự đồng bộ Room.status/Tenant.room_id theo vòng đời hợp đồng, mirror người đứng tên vào
        // minihouse_contract_tenants — xem ContractObserver.
        Contract::observe(ContractObserver::class);
        // Quét CCCD tự động + đồng bộ lại "Khai báo lưu trú" cho Khách thuê (đứng tên chính lẫn ở
        // cùng — đều là Tenant thật từ khi gộp ContractOccupant vào Tenant) — xem TenantObserver.
        Tenant::observe(TenantObserver::class);
        // Thêm/gỡ "Người ở cùng" chỉ đụng bảng trung gian, không tự fire sự kiện Contract/Tenant —
        // xem ContractTenantObserver.
        ContractTenant::observe(ContractTenantObserver::class);
        // Đồng bộ lại Invoice.amount_paid/paid_at/status mỗi khi 1 lần thanh toán được ghi/xoá —
        // xem InvoicePaymentObserver.
        InvoicePayment::observe(InvoicePaymentObserver::class);
        // Xoá thật các InvoicePayment (kéo theo Transaction liên kết) khi hoá đơn bị xoá mềm — xem
        // InvoiceObserver.
        Invoice::observe(InvoiceObserver::class);
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->publishes([
            module_path($this->moduleName, 'Config/config.php') => config_path($this->moduleNameLower . '.php'),
        ], 'config');
        $this->mergeConfigFrom(
            module_path($this->moduleName, 'Config/config.php'), $this->moduleNameLower
        );
    }

    public function registerViews()
    {
        $viewPath = resource_path('views/modules/' . $this->moduleNameLower);
        $sourcePath = module_path($this->moduleName, 'Resources/views');

        $this->publishes([
            $sourcePath => $viewPath,
        ], ['views', $this->moduleNameLower . '-module-views']);

        $this->loadViewsFrom(array_merge($this->getPublishableViewPaths(), [$sourcePath]), $this->moduleNameLower);
    }

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
