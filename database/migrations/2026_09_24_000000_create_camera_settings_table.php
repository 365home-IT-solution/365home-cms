<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Trước đây TOÀN BỘ camera (mọi đối tác) dùng CHUNG 1 server Frigate/go2rtc duy nhất
// (App\Settings\CameraSettings — Spatie Settings, 1 dòng). Yêu cầu 2026-09-24: nhiều đối tác giờ có
// server Frigate RIÊNG của họ — tách cấu hình theo TỪNG partner, mirror đúng pattern
// Modules\Minihouse\App\Models\BuildingSetting (bảng phụ 1-1, khoá chính = partner_id).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('camera_settings', function (Blueprint $table) {
            $table->uuid('partner_id')->primary();
            $table->string('base_url')->nullable();
            // api_key/username/password mã hoá qua Eloquent cast 'encrypted' ở App\Models\CameraSetting
            // (giống ZaloSetting/BuildingSetting) — cột kiểu text để đủ chỗ chứa chuỗi đã mã hoá.
            $table->text('api_key')->nullable();
            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->timestamps();

            $table->foreign('partner_id')->references('id')->on('partners')->cascadeOnDelete();
        });

        // Data migration: mọi camera hiện có đều đang dùng CHUNG 1 server Frigate cũ (bảng settings,
        // group=camera) — copy y nguyên giá trị đó cho MỌI đối tác đang có ít nhất 1 camera, để hành
        // vi KHÔNG đổi ngay sau khi deploy (đối tác nào muốn dùng Frigate riêng thì tự vào "Cấu hình
        // Camera" đổi sau, không bị mất kết nối đang chạy).
        if (! Schema::hasTable('settings')) {
            return;
        }

        $legacy = app(\App\Settings\CameraSettings::class);

        if (! $legacy->isConfigured()) {
            return;
        }

        $partnerIds = DB::table('cameras')
            ->whereNotNull('partner_id')
            ->whereIn('partner_id', DB::table('partners')->pluck('id'))
            ->distinct()
            ->pluck('partner_id');

        foreach ($partnerIds as $partnerId) {
            \App\Models\CameraSetting::create([
                'partner_id' => $partnerId,
                'base_url'   => $legacy->base_url,
                'api_key'    => $legacy->api_key,
                'username'   => $legacy->username,
                'password'   => $legacy->password,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('camera_settings');
    }
};
