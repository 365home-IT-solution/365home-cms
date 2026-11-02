<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình tiền mặt tại quầy chỉnh ở CMS: giờ huỷ khi khách không đến + ngưỡng chống huỷ đơn (đối tác / khách, theo ngày / tháng). null = theo .env.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'cash_payment')->where('name', 'no_show_hours')->exists()) {
            return;
        }

        foreach (['no_show_hours', 'guard_enabled', 'partner_day_limit', 'partner_month_limit', 'customer_day_limit', 'customer_month_limit'] as $name) {
            $this->migrator->add('cash_payment.' . $name, null);
        }
    }
};
