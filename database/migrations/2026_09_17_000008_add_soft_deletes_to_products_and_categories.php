<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Phòng/Toà nhà MiniHouse (nay là products/categories) cần giữ tính năng "Thùng rác/Khôi phục" đã
// có sẵn (xoá mềm) — products/categories của Home hiện KHÔNG hỗ trợ xoá mềm. Thêm cột deleted_at
// nullable là thay đổi CỘNG THÊM THUẦN TUÝ: Home không đọc/ghi cột này ở bất kỳ đâu (không có
// SoftDeletes trait trên Product/Category gốc), nên không ảnh hưởng hành vi hiện tại của Home.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'deleted_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        if (! Schema::hasColumn('categories', 'deleted_at')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'deleted_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }

        if (Schema::hasColumn('categories', 'deleted_at')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropSoftDeletes();
            });
        }
    }
};
