<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CaroSetting;
use App\Settings\CouponSettings;
use Modules\Promotion\App\Models\Coupon;

// Giới hạn số mã áp trong 1 đơn: tổng số mã (CouponSettings::max_coupons_per_order, mọi nguồn) và riêng số mã từ mini game Caro
// (caro_settings.max_caro_coupons_per_order). Mã độc quyền đi 1 mình do guardExclusiveCoupons() lo. Dùng ở mọi nơi áp mã:
// Booking/GuestBooking/OrderController và trang đặt phòng web.
class CouponStackLimit
{
    /** @param array<int, string> $codes Toàn bộ mã đang áp cho đơn. Trả về thông báo lỗi, hoặc null nếu hợp lệ. */
    public static function violation(array $codes): ?string
    {
        $codes = array_values(array_unique(array_map('strtoupper', $codes)));
        if (count($codes) < 2) {
            return null;
        }

        $max = app(CouponSettings::class)->max_coupons_per_order;
        if ($max !== null && count($codes) > $max) {
            return "Mỗi đơn chỉ áp tối đa {$max} mã giảm giá.";
        }

        $maxCaro = CaroSetting::query()->value('max_caro_coupons_per_order');
        if ($maxCaro !== null && Coupon::withoutGlobalScopes()->whereIn('code', $codes)->where('source', 'caro')->count() > $maxCaro) {
            return "Mỗi đơn chỉ áp tối đa {$maxCaro} mã từ mini game Caro.";
        }

        return null;
    }
}
