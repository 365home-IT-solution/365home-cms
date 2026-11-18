<?php

namespace Modules\VehicleRental\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Payment\Entities\Order;
use Modules\VehicleRental\App\Services\VehicleRoomLinkService;

class VehicleRentalServiceProvider extends ServiceProvider
{
    protected string $moduleName = 'VehicleRental';

    protected string $moduleNameLower = 'vehiclerental';

    public function boot(): void
    {
        $this->mergeConfigFrom(module_path($this->moduleName, 'Config/config.php'), $this->moduleNameLower);
        $this->loadMigrationsFrom(module_path($this->moduleName, 'Database/Migrations'));
        $this->loadViewsFrom(module_path($this->moduleName, 'Resources/views'), $this->moduleNameLower);

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\VehicleRental\App\Console\ExpirePendingBookingsCommand::class,
                \Modules\VehicleRental\App\Console\SendRemindersCommand::class,
                \Modules\VehicleRental\App\Console\GpsCheckCommand::class,
                \Modules\VehicleRental\App\Console\SeedSampleCommand::class,
            ]);
        }

        // Đơn phòng bị huỷ/hoàn tiền/thất bại → hỏi khách có muốn huỷ đơn thuê xe kèm theo (KHÔNG tự huỷ). Gắn bằng sự kiện
        // model để không phải sửa OrderObserver của luồng đặt phòng.
        Order::updated(fn (Order $order) => self::onRoomOrderUpdated($order));

        // GPS Motrak (module độc lập): sau mỗi lần đồng bộ, đánh giá cảnh báo cho các xe thuê đã ghép thiết bị. Module Motrak tắt → bỏ qua.
        if (class_exists(\Modules\Motrak\App\Events\MotrakSynced::class)) {
            Event::listen(\Modules\Motrak\App\Events\MotrakSynced::class, fn (\Modules\Motrak\App\Events\MotrakSynced $event) => self::onMotrakSynced($event));
        }
    }

    public function register(): void
    {
        // Xe đang trong chuyến thuê (hoặc vừa trả) được Motrak lưu lịch sử GPS đầy đủ để dựng hành trình làm bằng chứng.
        if (interface_exists(\Modules\Motrak\App\Contracts\HistoryPriorityResolver::class)) {
            $this->app->bind(\Modules\Motrak\App\Contracts\HistoryPriorityResolver::class, \Modules\VehicleRental\App\Services\RentalHistoryPriority::class);
        }
    }

    /** Xử lý khi 1 đơn phòng được cập nhật: chuyển sang trạng thái huỷ/hoàn tiền/thất bại thì báo khách về đơn thuê xe kèm theo. */
    public static function onRoomOrderUpdated(Order $order): void
    {
        if (! $order->wasChanged('status') || ! in_array($order->status, VehicleRoomLinkService::DEAD_STATUSES, true)) {
            return;
        }

        try {
            app(\Modules\VehicleRental\App\Services\VehicleRentalNotifier::class)->roomOrderCancelled($order);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Thuê xe: không báo được việc đơn phòng bị huỷ', ['order' => $order->order_code, 'error' => $e->getMessage()]);
        }
    }

    /** Sau khi đồng bộ GPS: kiểm tra cảnh báo cho các thiết bị có điểm mới. */
    public static function onMotrakSynced(\Modules\Motrak\App\Events\MotrakSynced $event): void
    {
        app(\Modules\VehicleRental\App\Services\VehicleGpsAlertService::class)->evaluateVehicles($event->vehicleIds);
    }
}
