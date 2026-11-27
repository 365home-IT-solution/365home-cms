<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chủ toà nhà thu tiền bằng Pay2S: tiền vào THẲNG tài khoản ngân hàng của chủ toà (owner_bank_*), Pay2S báo biến động số dư qua webhook của tài khoản đó.
// Pay2S sinh mỗi webhook một token riêng nên token lưu theo toà nhà (mã hoá khi lưu, không bao giờ trả ra API/form).
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('minihouse_building_settings', 'pay2s_webhook_token')) {
            Schema::table('minihouse_building_settings', function (Blueprint $table) {
                $table->text('pay2s_webhook_token')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('minihouse_building_settings', function (Blueprint $table) {
            $table->dropColumn('pay2s_webhook_token');
        });
    }
};
