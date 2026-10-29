<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Super Admin bật cho TỪNG đối tác: phải liên kết tài khoản PayOS riêng (của đối tác hoặc chi nhánh) mới được đăng bán phòng online.
    // Mặc định tắt — đối tác vẫn bán bằng PayOS chung của 365home như cũ. Xem App\Services\Payment\PartnerPayOsSalesGate.
    public function up(): void
    {
        if (Schema::hasColumn('partners', 'require_own_payos')) {
            return;
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('require_own_payos')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'require_own_payos')) {
            Schema::table('partners', fn (Blueprint $table) => $table->dropColumn('require_own_payos'));
        }
    }
};
