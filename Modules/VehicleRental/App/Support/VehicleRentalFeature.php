<?php

namespace Modules\VehicleRental\App\Support;

use Modules\VehicleRental\App\Settings\VehicleRentalSettings;
use Throwable;

/**
 * Công tắc "hiển thị phần thuê xe trên website khách". Mọi nơi quyết định hiện/ẩn lối vào thuê xe (menu, trang /thue-xe, tài khoản)
 * đều hỏi ở đây. Lỗi đọc cấu hình (chưa migrate settings...) = coi như TẮT để website không bao giờ vỡ vì tính năng này.
 */
class VehicleRentalFeature
{
    private static ?bool $memo = null;

    public static function clientEnabled(): bool
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        try {
            return self::$memo = (bool) app(VehicleRentalSettings::class)->client_enabled;
        } catch (Throwable) {
            return false; // không memo: lần sau thử lại
        }
    }

    public static function setClientEnabled(bool $enabled): void
    {
        $settings = app(VehicleRentalSettings::class);
        $settings->client_enabled = $enabled;
        $settings->save();
        self::forget();
    }

    public static function forget(): void
    {
        self::$memo = null;
        app()->forgetInstance(VehicleRentalSettings::class);
    }
}
