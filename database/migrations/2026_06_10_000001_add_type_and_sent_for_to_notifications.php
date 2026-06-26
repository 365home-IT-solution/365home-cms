<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->enum('type', ['manual', 'booking', 'checkin_reminder', 'checkout_warning'])
                  ->default('manual')
                  ->after('body');

            $table->enum('sent_for', ['all', 'users'])
                  ->default('users')
                  ->after('type');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('checkin_notified')->default(false)->after('checkout_notified');
        });
    }

    public function down(): void
    {
        Schema::table('notification_fcm', function (Blueprint $table) {
            $table->dropColumn(['type', 'sent_for']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('checkin_notified');
        });
    }
};
