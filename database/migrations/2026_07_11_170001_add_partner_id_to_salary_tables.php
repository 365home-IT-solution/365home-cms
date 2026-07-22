<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mức lương/phụ cấp/giảm trừ trước đây dùng CHUNG cho mọi đối tác — mỗi đối tác cần cách tính
// lương/phụ cấp riêng, nên tách theo partner_id giống toàn bộ dữ liệu nghiệp vụ khác trong hệ
// thống (Product, Order, Coupon...).
return new class extends Migration
{
    private const TABLES = ['allowance_types', 'deduction_types', 'salary_types', 'salary_templates'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasColumn($table, 'partner_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->uuid('partner_id')->nullable()->after('id');
                $blueprint->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasColumn($table, 'partner_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropForeign(['partner_id']);
                $blueprint->dropColumn('partner_id');
            });
        }
    }
};
