<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Phạm vi đối tác / chi nhánh của voucher tự tặng (điểm danh, mini game). Rỗng = chưa cấu hình: mã vẫn cấp nhưng dùng được mọi nơi
// và hệ thống ghi cảnh báo — Super Admin cần chọn ngay ở "Phạm vi voucher tự tặng".
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('voucher_scope.checkin_partner_ids', []);
        $this->migrator->add('voucher_scope.checkin_category_ids', []);
        $this->migrator->add('voucher_scope.minigame_partner_ids', []);
        $this->migrator->add('voucher_scope.minigame_category_ids', []);
    }
};
