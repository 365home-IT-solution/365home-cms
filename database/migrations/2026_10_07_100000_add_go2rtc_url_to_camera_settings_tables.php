<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('camera_settings', function (Blueprint $table): void {
            $table->string('go2rtc_url')->nullable()->after('base_url');
        });

        Schema::table('minihouse_camera_settings', function (Blueprint $table): void {
            $table->string('go2rtc_url')->nullable()->after('base_url');
        });
    }

    public function down(): void
    {
        Schema::table('camera_settings', function (Blueprint $table): void {
            $table->dropColumn('go2rtc_url');
        });

        Schema::table('minihouse_camera_settings', function (Blueprint $table): void {
            $table->dropColumn('go2rtc_url');
        });
    }
};
