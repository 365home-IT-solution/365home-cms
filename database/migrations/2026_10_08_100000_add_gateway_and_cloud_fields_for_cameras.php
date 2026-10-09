<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SETTINGS_TABLES = ['camera_settings', 'minihouse_camera_settings'];

    public function up(): void
    {
        foreach (self::SETTINGS_TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->string('gateway_type', 20)->default('frigate');
                $table->text('provider_credentials')->nullable();
            });
        }

        Schema::table('cameras', function (Blueprint $table): void {
            $table->string('external_channel', 20)->nullable()->after('external_device_id');
        });
    }

    public function down(): void
    {
        foreach (self::SETTINGS_TABLES as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->dropColumn(['gateway_type', 'provider_credentials']);
            });
        }

        Schema::table('cameras', function (Blueprint $table): void {
            $table->dropColumn('external_channel');
        });
    }
};
