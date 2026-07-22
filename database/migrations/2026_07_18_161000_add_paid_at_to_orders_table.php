<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Đơn THANH TOÁN ĐỦ (không cọc) trước giờ KHÔNG có mốc thời gian riêng ghi lại lúc chuyển sang
    // 'paid' — "Lịch sử thanh toán" phải dùng tạm updated_at, nhưng cột này bị chính Eloquent tự
    // cập nhật ở MỌI LẦN LƯU sau đó (thêm/bớt khung giờ, sửa ghi chú...), khiến mốc "Khách thanh
    // toán đầy đủ" hiển thị SAI — trôi tới đúng bằng thời điểm sửa đơn GẦN NHẤT, không phải lúc
    // thực sự thanh toán (đã xác nhận thực tế: đơn thanh toán lúc 15:31 nhưng do sau đó có sửa đơn
    // thêm khung giờ, "Khách thanh toán đầy đủ" lại hiện đúng 15:31... nhưng sẽ tiếp tục trôi nếu
    // sửa đơn thêm lần nữa). Thêm cột riêng, chỉ set lần ĐẦU TIÊN chuyển sang 'paid' và giữ nguyên
    // mãi mãi (xem OrderObserver::saving()).
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable()->after('deposit_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('paid_at');
        });
    }
};
