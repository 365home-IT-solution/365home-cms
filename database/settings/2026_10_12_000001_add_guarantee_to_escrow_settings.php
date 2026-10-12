<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Điều 4.2 hợp đồng mẫu CT: khoản tiền đảm bảo (đồng) chỉnh được ở Cấu hình web › Ký quỹ; mặc định 1.000.000đ. Hợp đồng tạo mới in đúng số này.
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('escrow.guarantee_vnd', 1000000);
    }
};
