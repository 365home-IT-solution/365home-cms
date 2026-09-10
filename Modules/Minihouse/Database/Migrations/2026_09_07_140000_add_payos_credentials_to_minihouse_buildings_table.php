<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép MỖI toà nhà (chủ sở hữu riêng) khai báo TÀI KHOẢN PAYOS CỦA CHÍNH HỌ — khi đủ 3 field
// này, QR thanh toán của toà đó tự chuyển sang dùng tài khoản PayOS riêng (tiền về thẳng tài khoản
// PayOS/ngân hàng liên kết của chủ toà, PayOS tự gọi webhook xác nhận y hệt cơ chế tài khoản PayOS
// chung của hệ thống) — không cần thêm bước "Chi hộ" chuyển tiền lại cho chủ toà, vì tiền vào ĐÚNG
// tài khoản của họ ngay từ đầu. Toà nào KHÔNG khai báo (để trống) thì tiếp tục dùng VietQR tĩnh theo
// owner_bank_* (xem migration add_owner_bank_info_to_minihouse_buildings_table) — xác nhận thủ công.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('payos_client_id')->nullable()->after('owner_bank_account_holder');
            $table->string('payos_api_key')->nullable()->after('payos_client_id');
            $table->string('payos_checksum_key')->nullable()->after('payos_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn(['payos_client_id', 'payos_api_key', 'payos_checksum_key']);
        });
    }
};
