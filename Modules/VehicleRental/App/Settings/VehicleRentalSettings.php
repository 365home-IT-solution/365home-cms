<?php

declare(strict_types=1);

namespace Modules\VehicleRental\App\Settings;

use Spatie\LaravelSettings\Settings;

// Cấu hình thuê xe (CMS → Thuê xe → Cài đặt thuê xe). Hiện chỉ có công tắc hiển thị phần thuê xe trên website khách.
class VehicleRentalSettings extends Settings
{
    /** Bật = website khách có trang /thue-xe và các lối vào (menu, tài khoản). Tắt = website như khi chưa có tính năng thuê xe. */
    public bool $client_enabled;

    public static function group(): string
    {
        return 'vehicle_rental';
    }
}
