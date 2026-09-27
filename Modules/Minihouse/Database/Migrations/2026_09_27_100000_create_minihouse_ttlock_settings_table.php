<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tài khoản TTLock THEO TỪNG TOÀ NHÀ MiniHouse (khoá chính = building_id, tức categories.id) — mỗi
// Toà nhà tự khai báo tài khoản TTLock Open Platform riêng, KHÔNG có 1 cấu hình dùng chung. Khác
// bảng ttlock_accounts của Home (1 tài khoản gắn nhiều chi nhánh qua bảng nối, lọc theo đối tác).
// client_secret/password_md5 mã hoá tại chỗ (cast encrypted) nên dùng kiểu text.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_ttlock_settings', function (Blueprint $table) {
            $table->unsignedBigInteger('building_id')->primary();
            $table->foreign('building_id', 'mh_ttlock_building_fk')->references('id')->on('categories')->cascadeOnDelete();
            $table->string('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->string('username')->nullable();
            $table->text('password_md5')->nullable();
            $table->string('api_base')->default('https://euapi.ttlock.com');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_ttlock_settings');
    }
};
