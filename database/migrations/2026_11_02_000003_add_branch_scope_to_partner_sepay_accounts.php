<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tài khoản nhận tiền (SePay / Pay2S / xác nhận thủ công) theo CHI NHÁNH: category_id trống = tài khoản mặc định của cả đối tác (như trước đây),
// có category_id = tài khoản riêng của chi nhánh đó (và các khu vực con). Mỗi đối tác tối đa 1 tài khoản mặc định + 1 tài khoản cho mỗi chi nhánh —
// category_key (cột ảo = category_id hoặc 0) để MySQL ràng buộc duy nhất cả với dòng mặc định (UNIQUE bỏ qua NULL).
// Yêu cầu thanh toán lưu tài khoản đã phát hành (khách chuyển vào tài khoản chi nhánh nào thì chỉ xác nhận được cho tài khoản đó).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_sepay_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('partner_id')->index();
            $table->unsignedBigInteger('category_key')->virtualAs('ifnull(category_id, 0)');
        });

        // Thêm khoá mới (partner_id đứng đầu nên vẫn đáp ứng khoá ngoại partner_id) rồi mới gỡ ràng buộc "mỗi đối tác một tài khoản".
        Schema::table('partner_sepay_accounts', function (Blueprint $table) {
            $table->unique(['partner_id', 'category_key'], 'partner_sepay_accounts_partner_scope_unique');
        });

        Schema::table('partner_sepay_accounts', function (Blueprint $table) {
            $table->dropUnique(['partner_id']);
        });

        Schema::table('sepay_payment_links', function (Blueprint $table) {
            $table->unsignedBigInteger('partner_sepay_account_id')->nullable()->after('order_id')->index();
        });

        Schema::table('partner_pay2s_link_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->after('partner_id');
        });
    }

    public function down(): void
    {
        Schema::table('partner_pay2s_link_sessions', function (Blueprint $table) {
            $table->dropColumn('category_id');
        });

        Schema::table('sepay_payment_links', function (Blueprint $table) {
            $table->dropColumn('partner_sepay_account_id');
        });

        // Chỉ quay lại được khi mỗi đối tác còn đúng 1 tài khoản: bỏ các tài khoản chi nhánh trước.
        \Illuminate\Support\Facades\DB::table('partner_sepay_accounts')->whereNotNull('category_id')->delete();

        Schema::table('partner_sepay_accounts', function (Blueprint $table) {
            $table->unique(['partner_id']);
        });

        Schema::table('partner_sepay_accounts', function (Blueprint $table) {
            $table->dropUnique('partner_sepay_accounts_partner_scope_unique');
            $table->dropColumn(['category_key', 'category_id']);
        });
    }
};
