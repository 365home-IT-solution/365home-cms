<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_fcm')) {
            return;
        }

        $prefix = DB::getTablePrefix();
        DB::statement("ALTER TABLE {$prefix}notification_fcm MODIFY COLUMN sent_for ENUM('all', 'users', 'guests') DEFAULT 'users'");
    }

    public function down(): void
    {
        if (!Schema::hasTable('notification_fcm')) {
            return;
        }

        $prefix = DB::getTablePrefix();
        DB::statement("ALTER TABLE {$prefix}notification_fcm MODIFY COLUMN sent_for ENUM('all', 'users') DEFAULT 'users'");
    }
};
