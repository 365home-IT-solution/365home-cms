<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cho phép MỖI toà nhà khai báo tài khoản MoMo/VNPay CỦA CHÍNH HỌ — cùng mẫu với PayOS riêng (xem
// migration add_payos_credentials_to_minihouse_buildings_table): đủ field thì QR/link thanh toán
// của toà đó tự chuyển sang dùng đúng tài khoản này, tiền vào thẳng tài khoản của chủ toà. Khác
// PayOS ở chỗ MiniHouse KHÔNG có "tài khoản chung" để rơi về — Home chưa từng tích hợp MoMo/VNPay
// (chỉ có field lưu SĐT MoMo/mã VNPay của Partner để CHI HỘ hoa hồng, không phải cổng thu tiền) nên
// mỗi toà PHẢI tự đăng ký merchant riêng, không có phương án dự phòng nào khác ngoài VietQR tĩnh.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->string('momo_partner_code')->nullable()->after('payos_checksum_key');
            $table->string('momo_access_key')->nullable()->after('momo_partner_code');
            $table->string('momo_secret_key')->nullable()->after('momo_access_key');

            $table->string('vnpay_tmn_code')->nullable()->after('momo_secret_key');
            $table->string('vnpay_hash_secret')->nullable()->after('vnpay_tmn_code');
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropColumn(['momo_partner_code', 'momo_access_key', 'momo_secret_key', 'vnpay_tmn_code', 'vnpay_hash_secret']);
        });
    }
};
