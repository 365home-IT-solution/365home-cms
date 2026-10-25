<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Super Admin bật cho TỪNG đối tác: đặt phòng trên web (trang chi tiết phòng) phải tải CCCD 2 mặt — mặt có mã QR
    // + mặt còn lại — thay vì chỉ 1 mặt có mã QR (mặc định tắt). Xem Modules\BladeThemeV1\Livewire\ProductDetail.
    public function up(): void
    {
        if (Schema::hasColumn('partners', 'cccd_two_sides')) {
            return;
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('cccd_two_sides')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'cccd_two_sides')) {
            Schema::table('partners', fn (Blueprint $table) => $table->dropColumn('cccd_two_sides'));
        }
    }
};
