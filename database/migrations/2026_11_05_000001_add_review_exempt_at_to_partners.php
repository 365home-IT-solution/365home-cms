<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hồ sơ đối tác do Super Admin TẠO TRỰC TIẾP ở trang quản trị/API admin (không qua trang "Đăng ký hợp tác với 365 HOME"): không cần bước duyệt
// giấy tờ/phê duyệt hồ sơ — hồ sơ được tạo ở trạng thái đã duyệt, giấy tờ Super Admin tải lên được duyệt luôn. Lưu thành cột (không suy từ
// created_by) để các hồ sơ đã có từ trước giữ nguyên luồng duyệt cũ và quyền của người tạo đổi về sau cũng không làm đổi luồng.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->timestamp('review_exempt_at')->nullable()->after('verification_note');
        });
    }

    public function down(): void
    {
        Schema::table('partners', function (Blueprint $table) {
            $table->dropColumn('review_exempt_at');
        });
    }
};
