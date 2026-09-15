<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Push token (Web Push/FCM hoặc Expo) của khách thuê — hoàn toàn TÁCH BIỆT với bảng "fcm_tokens" của
// Home (App\Models\FcmToken, gắn App\Models\User) và cột "token_device" của Customer — không dùng
// chung bảng nào với Home để không rủi ro ảnh hưởng luồng push hiện có bên đó. 1 khách có thể đăng
// nhập nhiều thiết bị/trình duyệt cùng lúc nên KHÔNG giới hạn 1 token/tenant — chỉ đảm bảo 1 token
// vật lý (1 trình duyệt/thiết bị) không thuộc về 2 khách khác nhau cùng lúc (unique token, không
// unique theo cặp tenant_id+token).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('minihouse_tenant_push_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('minihouse_tenants')->cascadeOnDelete();
            $table->string('token')->unique();
            // 'web' | 'android' | 'ios' | null (chưa xác định) — chỉ để hiển thị/thống kê, KHÔNG dùng
            // để quyết định cách gửi (FcmService tự nhận diện qua định dạng token, xem isExpoToken()).
            $table->string('platform')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('minihouse_tenant_push_tokens');
    }
};
