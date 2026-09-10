<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            // nullOnDelete — xoá 1 Khu vực KHÔNG kéo theo xoá các toà nhà thuộc khu đó, chỉ gỡ liên
            // kết (toà nhà trở về "chưa thuộc khu vực nào").
            $table->foreignId('zone_id')->nullable()->after('id')
                ->constrained('minihouse_zones')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('minihouse_buildings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });
    }
};
