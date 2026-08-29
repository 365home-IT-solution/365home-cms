<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $table = DB::getTablePrefix() . 'notification_fcm';

        DB::statement("ALTER TABLE `{$table}` MODIFY `type` ENUM('manual', 'booking', 'checkin_reminder', 'checkout_warning', 'membership_auto_coupon', 'checkin_streak_reminder') NOT NULL DEFAULT 'manual'");
    }

    public function down(): void
    {
        $table = DB::getTablePrefix() . 'notification_fcm';

        DB::statement("ALTER TABLE `{$table}` MODIFY `type` ENUM('manual', 'booking', 'checkin_reminder', 'checkout_warning', 'membership_auto_coupon') NOT NULL DEFAULT 'manual'");
    }
};
