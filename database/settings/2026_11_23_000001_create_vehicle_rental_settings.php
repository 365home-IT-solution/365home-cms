<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

// Công tắc hiển thị phần thuê xe trên website khách. Mặc định TẮT: website giữ nguyên như trước cho tới khi Super Admin bật ở CMS.
return new class extends SettingsMigration
{
    public function up(): void
    {
        if (DB::table('settings')->where('group', 'vehicle_rental')->where('name', 'client_enabled')->exists()) {
            return;
        }

        $this->migrator->add('vehicle_rental.client_enabled', false);
    }
};
