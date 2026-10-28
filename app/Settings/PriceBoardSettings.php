<?php

declare(strict_types=1);

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

// Công tắc GIAI ĐOẠN 2 của bảng giá (menu "Cấu hình web" > "Bảng giá"): bảng giá có độ ưu tiên + thứ áp dụng, giá theo thứ của bộ giá theo ngày, và giá đơn tính theo NGÀY khách ở.
// null = chưa chỉnh ở CMS → theo biến môi trường PRICE_BOARDS_PHASE2; true / false = Super Admin đã chọn ở CMS (ưu tiên hơn .env). Xem Modules\Product\App\Models\PriceBoard::phase2Enabled().
class PriceBoardSettings extends Settings
{
    public ?bool $phase2;

    public static function group(): string
    {
        return 'price_boards';
    }
}
