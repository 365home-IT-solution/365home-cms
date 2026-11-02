<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Luật áp mã giảm giá chung cho mọi đơn (nút "Cài đặt áp mã" ở danh sách Khuyến mãi & Giảm giá). Xem App\Support\CouponStackLimit.
class CouponSettings extends Settings
{
    // Tối đa bao nhiêu mã (mọi nguồn) trong 1 đơn; null = không giới hạn. Mã độc quyền vẫn luôn phải dùng 1 mình.
    public ?int $max_coupons_per_order;

    public static function group(): string
    {
        return 'coupons';
    }
}
