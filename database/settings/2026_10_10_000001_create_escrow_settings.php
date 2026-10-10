<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Miễn phí tháng đầu cho đối tác ngoài Cần Thơ: mặc định TẮT (deploy không đổi hành vi nào cho tới khi Super Admin bật).
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('escrow.free_trial_enabled', false);
        $this->migrator->add('escrow.free_trial_months', 1);
        $this->migrator->add('escrow.immediate_province_codes', [92]); // 92 = Thành phố Cần Thơ
        $this->migrator->add('escrow.reminder_days', [7, 1]);
    }
};
