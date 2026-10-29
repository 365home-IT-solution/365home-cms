<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mã khách ghi trong nội dung chuyển khoản (code) phải NGẮN (8 chữ số, vừa cấu trúc mã thanh toán mặc định của SePay: hậu tố 6–8 ký tự), không dùng
 * mã đơn dài. ref_code giữ mã mà luồng đặt phòng truyền vào (order_code / mã còn lại / mã phát sinh...) để biết đây là khoản thu nào của đơn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sepay_payment_links', 'ref_code')) {
            Schema::table('sepay_payment_links', function (Blueprint $table) {
                $table->unsignedBigInteger('ref_code')->nullable()->after('code')->index();
            });
        }

        // Yêu cầu tạo trước đây dùng chính mã luồng đặt phòng làm mã chuyển khoản.
        DB::table('sepay_payment_links')->whereNull('ref_code')->update(['ref_code' => DB::raw('code')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sepay_payment_links', 'ref_code')) {
            Schema::table('sepay_payment_links', function (Blueprint $table) {
                $table->dropColumn('ref_code');
            });
        }
    }
};
