<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Khoản phụ thu admin gõ tay ở Section "Tổng thanh toán" (OrderForm), CỘNG THẲNG vào 'amount'
    // lúc tính tổng (giống cách 'orderServices' được cộng vào) — lưu riêng cột này để còn hiển thị/
    // sửa lại đúng số đã nhập khi mở lại đơn, không lẫn vào 'amount' tổng (vốn có thể bị ghi đè bởi
    // chính admin hoặc bởi calculateTotal() mỗi khi đổi phòng/dịch vụ).
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('surcharge')->default(0)->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('surcharge');
        });
    }
};
