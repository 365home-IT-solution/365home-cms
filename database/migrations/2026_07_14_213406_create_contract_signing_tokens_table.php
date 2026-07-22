<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // OAuth2 access/refresh token cho API CSC chuẩn (VNPT SmartCA) — chỉ 1 dòng/provider vì
        // công ty chỉ dùng 1 chứng thư số Remote chung. refresh_token sống ~3 tháng (theo tài liệu
        // tích hợp) nên hiếm khi cần gửi lại mật khẩu thuê bao.
        Schema::create('contract_signing_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->unique();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contract_signing_tokens');
    }
};
