<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Super Admin bật cho TỪNG đối tác quyền tự nhập kênh PayOS của mình (mặc định tắt = chỉ 365home nhập hộ) —
    // xem App\Services\Payment\PartnerPayOsChannelService::setPartnerSetupAllowed().
    public function up(): void
    {
        if (Schema::hasColumn('partners', 'payos_self_setup_enabled')) {
            return;
        }

        Schema::table('partners', function (Blueprint $table) {
            $table->boolean('payos_self_setup_enabled')->default(false);
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('partners', 'payos_self_setup_enabled')) {
            Schema::table('partners', fn (Blueprint $table) => $table->dropColumn('payos_self_setup_enabled'));
        }
    }
};
