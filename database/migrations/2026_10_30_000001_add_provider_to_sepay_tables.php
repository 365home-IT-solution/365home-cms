<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cổng nhận biến động số dư của đối tác: 'sepay' (mặc định, giữ nguyên dữ liệu cũ) hoặc 'pay2s'. Hai cổng dùng chung bảng tài khoản / giao dịch /
 * yêu cầu thanh toán nên luồng xác nhận đơn giống hệt nhau. Mã giao dịch của mỗi cổng là dãy số riêng nên chống trùng theo (provider, sepay_id).
 * Pay2S tạo MỖI webhook một token riêng (gắn với một tài khoản ngân hàng) nên token lưu theo tài khoản của đối tác (mã hoá).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Idempotent: MySQL không hoàn tác DDL, chạy lại sau lỗi giữa chừng vẫn đúng.
        if (! Schema::hasColumn('partner_sepay_accounts', 'provider')) {
            Schema::table('partner_sepay_accounts', function (Blueprint $table) {
                $table->string('provider', 20)->default('sepay')->after('partner_id');
                $table->text('webhook_token')->nullable()->after('note');
            });
        }

        if (! Schema::hasColumn('sepay_transactions', 'provider')) {
            Schema::table('sepay_transactions', function (Blueprint $table) {
                $table->string('provider', 20)->default('sepay')->after('id');
            });
        }

        // Chỉ mục có tiền tố bảng (cms_...) nên tìm theo cột, không theo tên cứng.
        $indexes = collect(Schema::getIndexes('sepay_transactions'));
        $has = fn (array $columns) => $indexes->contains(fn ($i) => $i['unique'] && $i['columns'] === $columns);

        if (! $has(['provider', 'sepay_id'])) {
            Schema::table('sepay_transactions', function (Blueprint $table) {
                $table->unique(['provider', 'sepay_id']);
            });
        }

        if ($has(['sepay_id'])) {
            Schema::table('sepay_transactions', function (Blueprint $table) {
                $table->dropUnique(['sepay_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('sepay_transactions', function (Blueprint $table) {
            $table->unique('sepay_id');
        });

        Schema::table('sepay_transactions', function (Blueprint $table) {
            $table->dropUnique(['provider', 'sepay_id']);
            $table->dropColumn('provider');
        });

        Schema::table('partner_sepay_accounts', function (Blueprint $table) {
            $table->dropColumn(['provider', 'webhook_token']);
        });
    }
};
