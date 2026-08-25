<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// slug ở 4 bảng lương đang unique TOÀN CỤC — nhưng từ khi thêm partner_id (mỗi đối tác quản lý
// riêng), 2 đối tác khác nhau phải được phép cùng dùng 1 slug (vd "phu-cap-an-trua"). Đổi lại
// thành unique theo cặp (partner_id, slug).
return new class extends Migration
{
    private const TABLES = ['allowance_types', 'deduction_types', 'salary_types', 'salary_templates'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique("cms_{$table}_slug_unique");
                $blueprint->unique(['partner_id', 'slug']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique(['partner_id', 'slug']);
                $blueprint->unique('slug');
            });
        }
    }
};
