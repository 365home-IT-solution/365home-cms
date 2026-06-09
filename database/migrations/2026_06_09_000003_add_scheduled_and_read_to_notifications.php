<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->timestamp('scheduled_at')->nullable()->after('body');
        });

        Schema::table('notification_fcm_recipients', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->dropColumn('scheduled_at');
        });

        Schema::table('notification_fcm_recipients', function (Blueprint $table) {
            $table->dropColumn('read_at');
        });
    }
};
