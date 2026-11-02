<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Cấu hình "xác nhận thủ công" (không qua cổng) chỉnh ở CMS — Cấu hình web → Xác nhận thủ công; null = theo .env (MANUAL_PAYMENT_*).
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'manual_payment')->where('name', 'hold_minutes')->exists()) {
            return;
        }

        $this->migrator->add('manual_payment.hold_minutes', null);
        $this->migrator->add('manual_payment.claimed_hold_minutes', null);
    }
};
