<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ghi nhớ đúng order_guest_cccds row nào được SAO CHÉP từ CustomerCompanion nào (popup
        // "CCCD thành viên" — OrderForm::buildMemberCccdAction() — copy thẳng cccd_front/back/data
        // vào đây, KHÔNG lưu lại companion_id nên khi mở lại popup không biết đã chọn ai trước đó,
        // Select "Chọn người đi cùng đã lưu trong hồ sơ" luôn hiện trống dù đã lưu companion trước
        // đó). NULL cho khách vãng lai (upload CCCD tay riêng từng đơn, không qua companion nào).
        Schema::table('order_guest_cccds', function (Blueprint $table) {
            $table->unsignedBigInteger('companion_id')->nullable()->after('guest_index');
            $table->foreign('companion_id')->references('id')->on('customer_companions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_guest_cccds', function (Blueprint $table) {
            $table->dropForeign(['companion_id']);
            $table->dropColumn('companion_id');
        });
    }
};
