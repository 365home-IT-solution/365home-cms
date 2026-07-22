<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'partner_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            // Chỉ có ý nghĩa lọc quyền với category_type = 'product' (chi nhánh).
            // Category loại 'post' vẫn dùng chung, không lọc theo partner_id (xử lý ở tầng Resource).
            $table->uuid('partner_id')->nullable()->after('category_type');
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('categories', 'partner_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['partner_id']);
            $table->dropColumn('partner_id');
        });
    }
};
