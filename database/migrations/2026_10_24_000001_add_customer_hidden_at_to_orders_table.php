<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// KHÁCH "xoá" đơn ở trang thông tin / app = chỉ ẨN khỏi danh sách đơn của chính khách: đơn vẫn nguyên trong hệ thống, admin vẫn thấy, doanh thu,
// đối soát, hoa hồng, mini game không đổi. Khác hidden_from_lookup (cờ do ADMIN ẩn khỏi trang tra cứu công khai).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('orders', 'customer_hidden_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('customer_hidden_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('orders', 'customer_hidden_at')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_hidden_at']);
            $table->dropColumn('customer_hidden_at');
        });
    }
};
