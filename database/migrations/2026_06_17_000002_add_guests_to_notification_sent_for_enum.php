<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE notification_fcm MODIFY COLUMN sent_for ENUM('all', 'users', 'guests') DEFAULT 'users'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE notification_fcm MODIFY COLUMN sent_for ENUM('all', 'users') DEFAULT 'users'");
    }
};
