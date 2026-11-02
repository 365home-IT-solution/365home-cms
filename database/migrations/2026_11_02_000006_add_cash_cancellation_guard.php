<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Chống "đi cửa sau" với đơn tiền mặt tại quầy: ghi lúc / nguồn huỷ của từng đơn để đếm tỷ lệ huỷ theo đối tác và theo khách; đối tác vượt ngưỡng thì
// mọi phòng đang đăng bị tạm khoá (nhớ danh sách để mở lại đúng các phòng đó khi Super Admin mở khoá).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->index();
            // partner = tài khoản đối tác huỷ · admin = Super Admin / 365home huỷ · no_show = hệ thống huỷ vì khách không đến · system = huỷ tự động khác
            $table->string('cancel_source', 20)->nullable();
        });

        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('cancel_locked_at')->nullable();
            $table->string('cancel_lock_reason')->nullable();
            $table->json('cancel_locked_product_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn(['cancel_locked_at', 'cancel_lock_reason', 'cancel_locked_product_ids']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['cancelled_at']);
            $table->dropColumn(['cancelled_at', 'cancel_source']);
        });
    }
};
