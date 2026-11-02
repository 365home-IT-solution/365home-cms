<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Mặc định mỗi đơn áp tối đa 3 mã giảm giá (mọi nguồn: Caro, hạng thành viên, khuyến mãi...). Super Admin đổi ở "Cài đặt áp mã".
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('coupons.max_coupons_per_order', 3);
    }
};
